<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\Rupiah;
use App\Domain\Customers\Actions\AuthenticateCustomerAction;
use App\Domain\Customers\Actions\RegisterCustomerAction;
use App\Domain\Customers\Exceptions\TooManyPinAttemptsException;
use App\Domain\Sessions\Exceptions\UnitAlreadyActiveException;
use App\Domain\Settings\SettingKey;
use App\Domain\Wallet\Actions\OpenTopUpAction;
use App\Domain\Wallet\Actions\PlayFromWalletAction;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Unit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

/**
 * Layar yang dilihat pelanggan setelah memindai QR di unitnya.
 *
 * Satu komponen, beberapa keadaan — bukan beberapa halaman. Pelanggan berdiri
 * di depan TV sambil memegang HP; setiap perpindahan halaman adalah satu
 * kesempatan lagi untuk tersesat atau menutup tab dan kehilangan tagihannya.
 */
new class extends Component
{
    use WithFileUploads;

    public Unit $unit;

    // Masuk / daftar
    public string $phone = '';

    public string $pin = '';

    public string $name = '';

    public bool $registering = false;

    // Memesan
    public ?int $packageId = null;

    public ?string $method = null;

    public ?int $topUpAmount = null;

    public ?int $paymentId = null;

    public ?string $qrUrl = null;

    public $proof;

    public ?string $error = null;

    public ?string $notice = null;

    public function mount(Unit $unit): void
    {
        $this->unit = $unit;
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return Auth::guard('customer')->user();
    }

    #[Computed]
    public function packages()
    {
        return Package::query()
            ->where('unit_type_id', $this->unit->unit_type_id)
            ->where('is_active', true)
            ->orderBy('duration_minutes')
            ->get();
    }

    #[Computed]
    public function payment(): ?Payment
    {
        return $this->paymentId ? Payment::find($this->paymentId) : null;
    }

    #[Computed]
    public function activeSession()
    {
        return $this->unit->fresh()->activeSession;
    }

    #[Computed]
    public function transferAccount(): array
    {
        return [
            'bank' => Setting::get(SettingKey::TransferBankName),
            'number' => Setting::get(SettingKey::TransferAccountNumber),
            'holder' => Setting::get(SettingKey::TransferAccountHolder),
        ];
    }

    /**
     * Transfer hanya ditawarkan bila rekeningnya lengkap: menawarkannya dengan
     * rekening kosong berarti mengirim pelanggan ke tujuan yang tidak ada.
     */
    #[Computed]
    public function availableMethods(): array
    {
        return Setting::transferAccountIsComplete()
            ? [PaymentMethod::Qris, PaymentMethod::Transfer]
            : [PaymentMethod::Qris];
    }

    public function signIn(): void
    {
        $this->error = null;

        try {
            $customer = app(AuthenticateCustomerAction::class)->handle($this->phone, $this->pin);
        } catch (TooManyPinAttemptsException $exception) {
            $this->error = $exception->getMessage();

            return;
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();

            return;
        }

        Auth::guard('customer')->login($customer);
        $this->reset('phone', 'pin', 'name', 'registering');
    }

    public function register(): void
    {
        $this->error = null;

        try {
            $customer = app(RegisterCustomerAction::class)->handle($this->name, $this->phone, $this->pin);
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();

            return;
        }

        Auth::guard('customer')->login($customer);
        $this->reset('phone', 'pin', 'name', 'registering');
    }

    public function signOut(): void
    {
        Auth::guard('customer')->logout();
        $this->reset('packageId', 'method', 'paymentId', 'qrUrl', 'topUpAmount');
    }

    public function play(): void
    {
        $this->error = null;
        $this->validate(['packageId' => 'required|integer'], attributes: ['packageId' => 'paket']);

        try {
            app(PlayFromWalletAction::class)->handle(
                $this->customer,
                $this->unit,
                Package::findOrFail($this->packageId),
            );
        } catch (InsufficientBalanceException) {
            $this->error = 'Saldo belum cukup untuk paket ini. Isi saldo dulu.';

            return;
        } catch (UnitAlreadyActiveException) {
            $this->error = 'Unit ini baru saja dipakai orang lain.';

            return;
        }

        unset($this->activeSession);
    }

    public function topUp(): void
    {
        $this->error = null;
        $this->validate([
            'topUpAmount' => 'required|integer|min:'.OpenTopUpAction::MINIMUM.'|max:'.OpenTopUpAction::MAXIMUM,
            'method' => 'required|in:qris,transfer',
        ], attributes: ['topUpAmount' => 'nominal', 'method' => 'metode pembayaran']);

        try {
            ['payment' => $payment, 'qr_url' => $qrUrl] = app(OpenTopUpAction::class)->handle(
                $this->customer,
                (int) $this->topUpAmount,
                PaymentMethod::from($this->method),
            );
        } catch (Throwable $exception) {
            // Pesan mentah tidak pernah ditampilkan: isinya bisa memuat detail
            // gateway atau jalur berkas. Yang berguna bagi pelanggan hanyalah
            // langkah berikutnya.
            report($exception);
            $this->error = 'Pembayaran sedang tidak bisa dibuat. Coba lagi sebentar, atau isi saldo lewat kasir.';

            return;
        }

        $this->paymentId = $payment->id;
        $this->qrUrl = $qrUrl;
    }

    /**
     * Dipanggil polling. Sengaja hanya MEMBACA — yang memajukan status
     * pembayaran tetap penjadwal yang bertanya ke gateway, supaya pelanggan
     * tidak pernah bisa mendorong statusnya sendiri.
     */
    public function refreshStatus(): void
    {
        unset($this->payment, $this->activeSession, $this->customer);

        if ($this->payment?->status === PaymentStatus::Paid) {
            $this->notice = 'Saldo sudah bertambah.';
            $this->reset('paymentId', 'qrUrl', 'topUpAmount', 'method');
        }
    }

    public function uploadProof(): void
    {
        $this->validate(['proof' => 'required|image|max:4096'], attributes: ['proof' => 'bukti transfer']);

        $payment = $this->payment;

        if (! $payment || $payment->status !== PaymentStatus::Pending) {
            $this->error = 'Tagihan ini sudah tidak menunggu bukti.';

            return;
        }

        // Disk PRIVAT: bukti transfer memuat nama & nomor rekening orang.
        $payment->update([
            'status' => PaymentStatus::AwaitingVerification,
            'proof_path' => $this->proof->store('payment-proofs', 'local'),
        ]);

        $this->proof = null;
        unset($this->payment);
    }
};
?>


<div class="kiosk" wire:poll.5s="refreshStatus">
    <div class="kiosk-head">
        <p class="kiosk-brand">Creative Trees Billing Game</p>
        <h1 class="kiosk-unit">{{ $unit->code }}</h1>
        <p class="kiosk-type">{{ $unit->unitType->name }}</p>
    </div>

    {{-- Unit sedang dipakai: tidak ada yang bisa dilakukan siapa pun, jadi
         tidak ada form yang ditawarkan. --}}
    @if ($this->activeSession)
        <div class="card">
            <p class="label center">Unit sedang dipakai</p>
            <p class="timer"
               x-data="{ display: '--:--:--' }"
               x-init="
                   const ends = new Date('{{ $this->activeSession->ends_at?->toIso8601String() }}');
                   const tick = () => {
                       const s = Math.max(0, Math.floor((ends - new Date()) / 1000));
                       display = [Math.floor(s/3600), Math.floor(s/60)%60, s%60]
                           .map(n => String(n).padStart(2,'0')).join(':');
                   };
                   tick(); setInterval(tick, 1000);
               "
               x-text="display">--:--:--</p>
            <p class="muted center">Pindai lagi kode ini setelah unit selesai dipakai.</p>
        </div>

    @elseif (! $this->customer)
        <div class="card">
            <p class="label center">{{ $registering ? 'Buat akun' : 'Masuk untuk mulai main' }}</p>

            <form wire:submit="{{ $registering ? 'register' : 'signIn' }}">
                @if ($registering)
                    <input type="text" wire:model="name" maxlength="60" placeholder="Nama" class="field" required>
                @endif

                <input type="tel" wire:model="phone" inputmode="numeric" autocomplete="tel"
                       placeholder="Nomor WhatsApp — 081234567890" class="field" required>

                <input type="password" wire:model="pin" inputmode="numeric" maxlength="6" autocomplete="off"
                       placeholder="{{ $registering ? 'Buat PIN 6 angka' : 'PIN' }}" class="field" required>

                @if ($error)
                    <p class="alert">{{ $error }}</p>
                @endif

                <button type="submit" class="btn">
                    {{ $registering ? 'Daftar & lanjut' : 'Masuk' }}
                </button>
            </form>

            <button type="button" class="linkish"
                    wire:click="$toggle('registering')">
                {{ $registering ? 'Sudah punya akun? Masuk' : 'Belum punya akun? Daftar' }}
            </button>
        </div>

    @elseif ($this->payment?->status === PaymentStatus::AwaitingVerification)
        <div class="card">
            <p class="emoji">&#128340;</p>
            <p class="title">Menunggu kasir memeriksa</p>
            <p class="muted center">Bukti sudah terkirim. Saldo bertambah begitu kasir memastikan uangnya masuk.</p>
        </div>

    @elseif ($this->payment?->status === PaymentStatus::Pending && $this->payment->method === PaymentMethod::Qris)
        <div class="card">
            <p class="label center">Isi saldo dengan QRIS</p>
            <p class="amount">{{ Rupiah::format($this->payment->amount) }}</p>
            @if ($qrUrl)
                <img src="{{ $qrUrl }}" alt="Kode QRIS" class="qr">
            @endif
            <p class="muted center" style="margin-top:.875rem">
                Pindai dengan aplikasi bank atau e-wallet.<br>Halaman ini berpindah sendiri setelah pembayaran masuk.
            </p>
        </div>

    @elseif ($this->payment?->status === PaymentStatus::Pending && $this->payment->method === PaymentMethod::Transfer)
        <div class="card">
            <p class="label center">Transfer ke rekening</p>
            <p class="amount">{{ Rupiah::format($this->payment->amount) }}</p>
            <div class="account">
                <p class="account-bank">{{ $this->transferAccount['bank'] }}</p>
                <p class="account-number">{{ $this->transferAccount['number'] }}</p>
                <p class="muted">a.n. {{ $this->transferAccount['holder'] }}</p>
            </div>
            <p class="muted center">Transfer dengan nominal <strong>persis</strong> seperti di atas, lalu unggah buktinya.</p>
            <form wire:submit="uploadProof">
                <input type="file" wire:model="proof" accept="image/*" class="field">
                @error('proof') <p class="error">{{ $message }}</p> @enderror
                <button type="submit" class="btn" wire:loading.attr="disabled" wire:target="uploadProof">
                    <span wire:loading.remove wire:target="uploadProof">Kirim bukti transfer</span>
                    <span wire:loading wire:target="uploadProof">Mengirim&hellip;</span>
                </button>
            </form>
        </div>

    @else
        {{-- Saldo ditaruh PALING ATAS: itu satu-satunya angka yang menentukan
             apakah pelanggan bisa langsung main atau harus isi dulu. --}}
        <div class="card">
            <p class="label center">Halo, {{ $this->customer->name }}</p>
            <p class="amount">{{ Rupiah::format($this->customer->balance) }}</p>
            <p class="muted center">Saldo Anda</p>
            @if ($notice)
                <p class="notice">{{ $notice }}</p>
            @endif
        </div>

        <div class="card">
            <p class="label">Pilih paket</p>
            @foreach ($this->packages as $package)
                @php($terjangkau = $this->customer->canAfford($package->price))
                <label class="option {{ $terjangkau ? '' : 'option-off' }}" wire:key="pkg-{{ $package->id }}">
                    <span>
                        <input type="radio" wire:model.live="packageId" value="{{ $package->id }}" @disabled(! $terjangkau)>
                        <span class="option-name">{{ $package->name }}</span>
                        <span class="option-sub">
                            {{ $package->duration_minutes }} menit
                            @unless ($terjangkau) &middot; saldo kurang @endunless
                        </span>
                    </span>
                    <span class="option-price">{{ Rupiah::format($package->price) }}</span>
                </label>
            @endforeach
            @error('packageId') <p class="error">{{ $message }}</p> @enderror

            @if ($error)
                <p class="alert">{{ $error }}</p>
            @endif

            <button type="button" class="btn" wire:click="play" wire:loading.attr="disabled" wire:target="play">
                <span wire:loading.remove wire:target="play">Mulai main</span>
                <span wire:loading wire:target="play">Menyalakan TV&hellip;</span>
            </button>
        </div>

        <div class="card">
            <p class="label">Isi saldo</p>
            <div class="methods" style="margin-bottom:.5rem">
                @foreach ([25000, 50000, 100000] as $nominal)
                    <label class="option" wire:key="amt-{{ $nominal }}">
                        <input type="radio" wire:model.live="topUpAmount" value="{{ $nominal }}">
                        <span class="option-name">{{ Rupiah::format($nominal) }}</span>
                    </label>
                @endforeach
            </div>
            @error('topUpAmount') <p class="error">{{ $message }}</p> @enderror

            <div class="methods">
                @foreach ($this->availableMethods as $available)
                    <label class="option" wire:key="m-{{ $available->value }}">
                        <input type="radio" wire:model.live="method" value="{{ $available->value }}">
                        <span class="option-name">{{ $available->getLabel() }}</span>
                    </label>
                @endforeach
            </div>
            @error('method') <p class="error">{{ $message }}</p> @enderror
            <p class="muted" style="margin-top:.5rem;font-size:.75rem">Isi saldo tunai? Silakan ke kasir.</p>

            <button type="button" class="btn btn-quiet" wire:click="topUp" wire:loading.attr="disabled" wire:target="topUp">
                <span wire:loading.remove wire:target="topUp">Isi saldo</span>
                <span wire:loading wire:target="topUp">Menyiapkan&hellip;</span>
            </button>
        </div>

        <button type="button" class="linkish" wire:click="signOut">Keluar</button>
    @endif

    <p class="foot">Sesi baru berjalan setelah pembayaran diterima.</p>
</div>
