<?php

use App\Domain\Billing\Actions\OpenKioskCheckoutAction;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\Rupiah;
use App\Domain\Sessions\Exceptions\UnitAlreadyActiveException;
use App\Domain\Settings\SettingKey;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Unit;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

/**
 * Layar yang dilihat pelanggan setelah memindai QR di unit.
 *
 * Tidak ada login, tidak ada akun: pelanggan berdiri di depan unitnya, dan
 * memindai kode fisik di sana SUDAH membuktikan ia ada di tempat. Yang
 * dijaga bukan identitasnya, melainkan uangnya — sesi baru berjalan setelah
 * pembayaran terbukti (lihat OpenKioskCheckoutAction).
 */
new class extends Component
{
    use WithFileUploads;

    public Unit $unit;

    public ?int $packageId = null;

    public ?string $method = null;

    public string $customerName = '';

    public ?int $paymentId = null;

    public ?string $qrUrl = null;

    public $proof;

    public ?string $error = null;

    public function mount(Unit $unit): void
    {
        $this->unit = $unit;
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
     * Transfer hanya ditawarkan bila rekeningnya sudah lengkap. Menawarkannya
     * dengan rekening kosong berarti mengirim pelanggan ke tujuan yang tidak
     * ada, dan uangnya nyasar tanpa ada yang bisa menelusurinya.
     */
    #[Computed]
    public function availableMethods(): array
    {
        $methods = [PaymentMethod::Qris];

        if (Setting::transferAccountIsComplete()) {
            $methods[] = PaymentMethod::Transfer;
        }

        return $methods;
    }

    public function order(): void
    {
        $this->error = null;

        $this->validate([
            'packageId' => 'required|integer',
            'method' => 'required|in:qris,transfer',
            'customerName' => 'nullable|string|max:100',
        ], attributes: [
            'packageId' => 'paket',
            'method' => 'metode pembayaran',
            'customerName' => 'nama',
        ]);

        try {
            ['payment' => $payment, 'qr_url' => $qrUrl] = app(OpenKioskCheckoutAction::class)->handle(
                $this->unit,
                Package::findOrFail($this->packageId),
                PaymentMethod::from($this->method),
                $this->customerName,
            );
        } catch (UnitAlreadyActiveException $exception) {
            $this->error = 'Unit ini baru saja dipakai orang lain. Coba unit lain atau tanya kasir.';

            return;
        } catch (Throwable $exception) {
            // Pesan mentah TIDAK ditampilkan ke pelanggan: isinya bisa memuat
            // detail gateway atau jalur berkas. Yang berguna baginya cuma
            // langkah berikutnya.
            report($exception);
            $this->error = 'Pembayaran sedang tidak bisa dibuat. Coba lagi sebentar, atau bayar lewat kasir.';

            return;
        }

        $this->paymentId = $payment->id;
        $this->qrUrl = $qrUrl;
    }

    /**
     * Dipanggil polling di halaman. Sengaja hanya MEMBACA — yang mengubah
     * status pembayaran tetap penjadwal yang bertanya ke gateway, supaya
     * pelanggan tidak pernah bisa mendorong status pembayarannya sendiri.
     */
    public function refreshStatus(): void
    {
        unset($this->payment, $this->activeSession);
    }

    public function uploadProof(): void
    {
        $this->validate([
            'proof' => 'required|image|max:4096',
        ], attributes: ['proof' => 'bukti transfer']);

        $payment = $this->payment;

        if (! $payment || $payment->status !== PaymentStatus::Pending) {
            $this->error = 'Tagihan ini sudah tidak menunggu bukti.';

            return;
        }

        // Disk PRIVAT: bukti transfer memuat nama & nomor rekening orang, dan
        // tidak boleh bisa dibuka siapa pun yang menebak alamatnya.
        $path = $this->proof->store('payment-proofs', 'local');

        $payment->update([
            'status' => PaymentStatus::AwaitingVerification,
            'proof_path' => $path,
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

    @elseif ($this->payment?->status === PaymentStatus::Paid)
        <div class="card">
            <p class="emoji">&#9989;</p>
            <p class="title">Pembayaran diterima</p>
            <p class="muted center">TV sedang dinyalakan. Selamat bermain!</p>
        </div>

    @elseif ($this->payment?->status === PaymentStatus::AwaitingVerification)
        <div class="card">
            <p class="emoji">&#128340;</p>
            <p class="title">Menunggu kasir memeriksa</p>
            <p class="muted center">Bukti sudah terkirim. Unit menyala begitu kasir memastikan uangnya masuk.</p>
        </div>

    @elseif ($this->payment?->status === PaymentStatus::Pending && $this->payment->method === PaymentMethod::Qris)
        <div class="card">
            <p class="label center">Bayar dengan QRIS</p>
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
        <div class="card">
            <form wire:submit="order">
                <p class="label">Pilih paket</p>
                @foreach ($this->packages as $package)
                    <label class="option" wire:key="pkg-{{ $package->id }}">
                        <span>
                            <input type="radio" wire:model.live="packageId" value="{{ $package->id }}">
                            <span class="option-name">{{ $package->name }}</span>
                            <span class="option-sub">{{ $package->duration_minutes }} menit</span>
                        </span>
                        <span class="option-price">{{ Rupiah::format($package->price) }}</span>
                    </label>
                @endforeach
                @error('packageId') <p class="error">{{ $message }}</p> @enderror

                <p class="label" style="margin-top:1rem">Cara bayar</p>
                <div class="methods">
                    @foreach ($this->availableMethods as $available)
                        <label class="option" wire:key="m-{{ $available->value }}">
                            <input type="radio" wire:model.live="method" value="{{ $available->value }}">
                            <span class="option-name">{{ $available->getLabel() }}</span>
                        </label>
                    @endforeach
                </div>
                @error('method') <p class="error">{{ $message }}</p> @enderror
                <p class="muted" style="margin-top:.5rem;font-size:.75rem">Bayar tunai? Silakan ke kasir.</p>

                <input type="text" wire:model="customerName" maxlength="100" placeholder="Nama (opsional)" class="field">

                @if ($error)
                    <p class="alert">{{ $error }}</p>
                @endif

                <button type="submit" class="btn" wire:loading.attr="disabled" wire:target="order">
                    <span wire:loading.remove wire:target="order">Bayar &amp; mulai main</span>
                    <span wire:loading wire:target="order">Menyiapkan&hellip;</span>
                </button>
            </form>
        </div>
    @endif

    <p class="foot">Sesi baru berjalan setelah pembayaran diterima.</p>
</div>
