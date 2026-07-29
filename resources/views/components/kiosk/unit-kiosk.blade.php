<?php

use App\Domain\Billing\Actions\SettleQrisPaymentAction;
use App\Domain\Billing\Actions\StartKioskOpenPlayAction;
use App\Domain\Billing\Actions\StopKioskOpenPlayAction;
use App\Domain\Billing\Events\TransferProofSubmitted;
use App\Domain\Billing\MidtransGateway;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\Rupiah;
use App\Domain\Discounts\DiscountEngine;
use App\Domain\Discounts\DiscountTarget;
use App\Domain\Discounts\Exceptions\DiscountNotApplicableException;
use App\Domain\Sessions\Exceptions\SessionTooShortException;
use App\Domain\Sessions\SessionType;
use App\Domain\Wallet\Exceptions\CreditNotAllowedException;
use App\Domain\Customers\Actions\AuthenticateCustomerAction;
use App\Domain\Customers\Actions\RegisterCustomerAction;
use App\Domain\Customers\CustomerPhone;
use App\Domain\Customers\Exceptions\TooManyPinAttemptsException;
use App\Domain\Customers\Otp\OtpService;
use App\Domain\Sessions\Exceptions\UnitAlreadyActiveException;
use App\Domain\Settings\SettingKey;
use App\Domain\Wallet\Actions\OpenTopUpAction;
use App\Domain\Wallet\Actions\PlayFromWalletAction;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\TopUpFee;
use App\Domain\Menu\Actions\PlaceMenuOrderAction;
use App\Domain\Menu\MenuOrderStatus;
use App\Domain\Menu\MenuServiceHours;
use App\Domain\Menu\MenuServiceStatus;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuOrder;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Unit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use Livewire\WithPagination;

/**
 * Layar yang dilihat pelanggan setelah memindai QR di unitnya.
 *
 * Satu komponen, beberapa keadaan — bukan beberapa halaman. Pelanggan berdiri
 * di depan TV sambil memegang HP; setiap perpindahan halaman adalah satu
 * kesempatan lagi untuk tersesat atau menutup tab dan kehilangan tagihannya.
 *
 * Alur masuk: nomor WhatsApp dulu → kalau nomornya sudah punya akun, kode OTP
 * dikirim ke WA-nya; kalau belum, daftar (nama + PIN). Kode OTP tetap bisa
 * dilewati dengan PIN yang dibuat saat mendaftar — supaya pelanggan tidak
 * pernah terkunci hanya karena WhatsApp-nya telat.
 */
new class extends Component
{
    use WithFileUploads;
    use WithPagination;

    public Unit $unit;

    /** phone → otp → (pin) untuk yang sudah punya akun; phone → register untuk yang belum. */
    public string $step = 'phone';

    public string $phone = '';

    public string $pin = '';

    public string $name = '';

    public string $code = '';

    /** ISO waktu OTP terakhir dikirim — hitung mundur "kirim ulang" dihitung dari sini. */
    public ?string $otpSentAt = null;

    // Memesan. playChoice = 'open' (Open Play) atau id paket sebagai string.
    public string $playChoice = '';

    public ?int $packageId = null;

    /** Kode voucher yang diketik pelanggan di modal konfirmasi paket. */
    public string $voucherCode = '';

    public ?string $method = null;

    public ?int $topUpAmount = null;

    /** Keranjang jajanan: [menu_item_id => jumlah]. Belum jadi pesanan sampai dibayar. */
    public array $cart = [];

    public ?int $paymentId = null;

    public ?string $qrUrl = null;

    public $proof;

    public ?string $error = null;

    public ?string $notice = null;

    /** Tab dasbor aktif: main (pilih paket) · topup (isi saldo) · history (riwayat). */
    public string $tab = 'main';

    /** Berapa transaksi per halaman di Riwayat. Diketik manual, default 5. */
    public int $perPage = 5;

    /** Halaman grid paket. Grid menampilkan 4 per halaman; pager muncul bila >4. */
    public int $packagePage = 1;

    /** Pembelian yang sedang menunggu konfirmasi: play · topup · null. */
    public ?string $confirm = null;

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
        // Dari cache (dibuang tepat saat paket berubah): dibaca tiap poll,
        // jadi tidak perlu query DB per ketukan.
        return Package::activeForUnitType($this->unit->unit_type_id);
    }

    /**
     * Inti pratinjau voucher — satu tempat menangani kode kosong & galat, dipakai
     * ketiga permukaan (paket, Open Play, isi saldo). Read-only: menampilkan
     * potongan sebelum bayar; penjaga sesungguhnya (kuota di bawah kunci) tetap
     * di dalam transaksi saat menebus.
     *
     * @return array{ok: bool, result?: \App\Domain\Discounts\DiscountResult, message?: string}|null
     *                Null bila kode kosong; ['ok'=>false,'message'] bila ditolak.
     */
    private function previewVoucher(DiscountTarget $target, int $base): ?array
    {
        $code = trim($this->voucherCode);

        if ($code === '') {
            return null;
        }

        try {
            return ['ok' => true, 'result' => app(DiscountEngine::class)->preview($code, $target, $base, $this->customer)];
        } catch (DiscountNotApplicableException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** Pratinjau voucher paket: potongan atas harga paket yang dikonfirmasi. */
    #[Computed]
    public function voucherResult(): ?array
    {
        $package = $this->packageId ? $this->packages->firstWhere('id', $this->packageId) : null;

        if (! $package) {
            return null;
        }

        $preview = $this->previewVoucher(DiscountTarget::Package, (int) $package->price);

        if (! $preview || ! $preview['ok']) {
            return $preview;
        }

        return ['ok' => true, 'discount' => $preview['result']->discount, 'final' => $preview['result']->finalAmount, 'label' => $preview['result']->label];
    }

    /**
     * Pratinjau voucher Open Play. Tagihannya belum ada di sini, jadi hanya
     * memvalidasi kode — potongannya baru terlihat saat berhenti.
     */
    #[Computed]
    public function openVoucherResult(): ?array
    {
        $preview = $this->previewVoucher(DiscountTarget::OpenPlay, 0);

        if (! $preview || ! $preview['ok']) {
            return $preview;
        }

        return ['ok' => true, 'label' => $preview['result']->label];
    }

    /** Pratinjau voucher isi saldo: bonus = potongan atas nominal top-up. */
    #[Computed]
    public function topUpVoucherResult(): ?array
    {
        if (! $this->topUpAmount) {
            return null;
        }

        $preview = $this->previewVoucher(DiscountTarget::TopUp, (int) $this->topUpAmount);

        if (! $preview || ! $preview['ok']) {
            return $preview;
        }

        return ['ok' => true, 'bonus' => $preview['result']->discount, 'label' => $preview['result']->label];
    }

    /** Jumlah halaman grid paket, 4 per halaman. */
    public function packagePageCount(): int
    {
        return max(1, (int) ceil($this->packages->count() / 4));
    }

    public function packagePrev(): void
    {
        $this->packagePage = max(1, $this->packagePage - 1);
    }

    public function packageNext(): void
    {
        $this->packagePage = min($this->packagePageCount(), $this->packagePage + 1);
    }

    #[Computed]
    public function payment(): ?Payment
    {
        // WAJIB lewat relasi pelanggan yang login, BUKAN Payment::find lepas:
        // paymentId properti publik yang bisa di-tamper. Tanpa filter pemilik,
        // pelanggan bisa menunjuk pembayaran orang lain — membaca nominalnya, dan
        // lewat uploadProof() menimpa bukti transfer pembayaran orang lain.
        return $this->paymentId ? $this->customer?->payments()->find($this->paymentId) : null;
    }

    #[Computed]
    public function activeSession()
    {
        return $this->unit->fresh()->activeSession;
    }

    /**
     * Halaman berapa pun kembali ke 1 begitu jumlah per halaman diubah —
     * kalau tidak, mengecilkan per-halaman saat berada di halaman 4 bisa
     * mendarat di halaman yang tidak ada lagi dan menampilkan daftar kosong.
     */
    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    /**
     * Riwayat saldo, dibaca langsung dari buku besar — satu-satunya sumber
     * kebenaran saldo — dan dipaginasi. Jumlah per halaman diketik pelanggan;
     * dijepit 1..50 di sini supaya angka ekstrem (0, kosong, atau ribuan)
     * tidak pernah menghasilkan kueri kosong atau berat.
     */
    #[Computed]
    public function transactions()
    {
        $perPage = max(1, min(50, $this->perPage));

        // Eager-load payment: tiap baris pemasukan menampilkan metode bayarnya
        // (QRIS/Transfer/Tunai), dan tanpa ini itu jadi N+1 — satu kueri per
        // baris, persis yang preventLazyLoading() tolak.
        return $this->customer
            ? $this->customer->walletTransactions()->with('payment')->latest()->paginate($perPage)
            : new Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
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

    /**
     * Biaya admin yang menempel pada isi saldo online (QRIS/transfer sama). Nol
     * bila owner mematikannya. Dipakai layar konfirmasi untuk menampilkan total
     * bayar; nilai sebenarnya tetap ditetapkan server di OpenTopUpAction.
     */
    #[Computed]
    public function topUpFee(): int
    {
        return TopUpFee::for(PaymentMethod::tryFrom((string) $this->method) ?? PaymentMethod::Qris);
    }

    // ─── Masuk ──────────────────────────────────────────────────────────────

    /**
     * Langkah pertama: cukup nomor. Nomor yang sudah punya akun dikirimi OTP;
     * yang belum diarahkan mendaftar. Membedakan keduanya di sini menjaga alur
     * tetap satu kolom — pelanggan tidak perlu tahu istilah "daftar" vs "masuk".
     */
    public function continueWithPhone(): void
    {
        $this->error = null;

        // Batas per-IP: langkah nomor ini satu-satunya pintu tanpa login. Tanpa
        // batas ini seorang penyerang di Wi-Fi outlet bisa menyapu daftar nomor
        // untuk menebak siapa yang member (cabang OTP vs daftar membocorkannya)
        // dan memicu OTP WhatsApp berbayar ke nomor asli. Batas per-nomor sudah
        // ada di OtpService; ini menutup penyapuan LINTAS-nomor dari satu sumber.
        $ipKey = 'kiosk-phone:'.request()->ip();

        if (RateLimiter::tooManyAttempts($ipKey, maxAttempts: 10)) {
            $this->error = 'Terlalu banyak percobaan. Coba lagi sebentar.';

            return;
        }

        RateLimiter::hit($ipKey, decaySeconds: 60);

        $phone = CustomerPhone::normalise($this->phone);

        if ($phone === null) {
            $this->error = 'Nomor WhatsApp tidak dikenali. Contoh: 081234567890';

            return;
        }

        if (Customer::query()->where('phone', $phone)->exists()) {
            $this->requestOtp();

            return;
        }

        $this->step = 'register';
    }

    private function requestOtp(): void
    {
        try {
            app(OtpService::class)->request($this->phone);
        } catch (TooManyPinAttemptsException $exception) {
            // OTP habis kuotanya. Nomor ini pasti milik member (hanya member yang
            // memicu OTP), jadi arahkan ke PIN sebagai jalan masuk — bukan
            // membiarkannya buntu di langkah nomor sampai jendela reset ~15 menit.
            $this->error = rtrim($exception->getMessage(), '.').'. Masuk pakai PIN saja.';
            $this->code = '';
            $this->step = 'pin';

            return;
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();

            return;
        }

        $this->code = '';
        $this->otpSentAt = now()->toIso8601String();
        $this->step = 'otp';
    }

    public function resendOtp(): void
    {
        $this->error = null;
        $this->requestOtp();
    }

    public function verifyOtp(): void
    {
        $this->error = null;

        try {
            $phone = app(OtpService::class)->verify($this->phone, $this->code);
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();
            $this->code = '';

            return;
        }

        $customer = Customer::query()->where('phone', $phone)->first();

        if (! $customer || ! $customer->is_active) {
            $this->error = 'Akun ini tidak bisa dipakai. Hubungi kasir.';

            return;
        }

        $this->signInAs($customer);
    }

    /** Jalur cadangan: kalau OTP tak kunjung datang, PIN pendaftaran tetap sah. */
    public function usePin(): void
    {
        $this->error = null;
        $this->code = '';
        $this->step = 'pin';
    }

    public function signInWithPin(): void
    {
        $this->error = null;

        try {
            $customer = app(AuthenticateCustomerAction::class)->handle($this->phone, $this->pin);
        } catch (TooManyPinAttemptsException $exception) {
            $this->error = $exception->getMessage();
            $this->pin = '';

            return;
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();
            // Dikosongkan supaya enam kotaknya ikut bersih: angka yang baru
            // saja ditolak tidak boleh menunggu di layar dalam bentuk titik-titik
            // yang tak bisa dibaca ulang pelanggan.
            $this->pin = '';

            return;
        }

        $this->signInAs($customer);
    }

    public function register(): void
    {
        $this->error = null;

        try {
            $customer = app(RegisterCustomerAction::class)->handle($this->name, $this->phone, $this->pin);
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();
            $this->pin = '';

            return;
        }

        $this->signInAs($customer);
    }

    private function signInAs(Customer $customer): void
    {
        Auth::guard('customer')->login($customer);
        $this->reset('phone', 'pin', 'name', 'code', 'otpSentAt', 'error');
        $this->step = 'phone';
    }

    /** Kembali ke langkah nomor tanpa membawa sisa kode/PIN yang salah. */
    public function editPhone(): void
    {
        $this->reset('pin', 'code', 'name', 'otpSentAt', 'error');
        $this->step = 'phone';
    }

    public function signOut(): void
    {
        Auth::guard('customer')->logout();
        $this->reset('playChoice', 'packageId', 'method', 'paymentId', 'qrUrl', 'topUpAmount', 'notice', 'error', 'tab', 'confirm');
    }

    // ─── Bermain & isi saldo (Fase 1: paket + top-up seperti sebelumnya) ──────

    // ─── Pesan makanan & minuman ────────────────────────────────────────────

    /** Menu yang boleh dipesan: kategori aktif yang masih punya item aktif. */
    #[Computed]
    public function menu()
    {
        return MenuCategory::activeWithItems();
    }

    /**
     * Buka / istirahat / tutup / dimatikan. Sumbernya sama persis dengan yang
     * dipakai PlaceMenuOrderAction untuk menolak, jadi layar tidak akan pernah
     * menawarkan sesuatu yang jalur uangnya akan tolak sedetik kemudian.
     */
    #[Computed]
    public function kitchen(): MenuServiceStatus
    {
        return MenuServiceHours::status();
    }

    #[Computed]
    public function kitchenNotice(): ?string
    {
        return MenuServiceHours::notice();
    }

    /**
     * Isi keranjang yang MASIH valid, dengan harga dari database — bukan dari
     * state di HP pelanggan. Item yang keburu dinonaktifkan hilang sendiri dari
     * daftar ini, jadi totalnya tidak pernah menagih barang yang sudah habis.
     *
     * @return array<int, array{item: MenuItem, quantity: int, subtotal: int}>
     */
    #[Computed]
    public function cartLines(): array
    {
        $wanted = array_filter($this->cart, fn ($quantity): bool => (int) $quantity > 0);

        if ($wanted === []) {
            return [];
        }

        return MenuItem::query()
            ->whereIn('id', array_keys($wanted))
            ->where('is_active', true)
            ->get()
            ->map(fn (MenuItem $item): array => [
                'item' => $item,
                'quantity' => (int) $this->cart[$item->id],
                'subtotal' => $item->price * (int) $this->cart[$item->id],
            ])
            ->all();
    }

    #[Computed]
    public function cartTotal(): int
    {
        return (int) array_sum(array_column($this->cartLines, 'subtotal'));
    }

    /** Pesanan yang masih dikerjakan staf — supaya pelanggan tahu statusnya. */
    #[Computed]
    public function openOrders()
    {
        if (! $this->customer) {
            return collect();
        }

        return MenuOrder::query()
            ->where('customer_id', $this->customer->id)
            ->whereIn('status', [MenuOrderStatus::Placed, MenuOrderStatus::Preparing])
            ->with('items')
            ->latest()
            ->get();
    }

    private function forgetCart(): void
    {
        unset($this->cartLines, $this->cartTotal);
    }

    public function addToCart(int $itemId): void
    {
        $this->error = null;

        $current = (int) ($this->cart[$itemId] ?? 0);

        if ($current >= PlaceMenuOrderAction::MAX_QUANTITY_PER_ITEM) {
            return;
        }

        $this->cart[$itemId] = $current + 1;
        $this->forgetCart();
    }

    public function removeFromCart(int $itemId): void
    {
        $current = (int) ($this->cart[$itemId] ?? 0);

        if ($current <= 1) {
            unset($this->cart[$itemId]);
        } else {
            $this->cart[$itemId] = $current - 1;
        }

        $this->forgetCart();
    }

    public function askOrder(): void
    {
        $this->error = null;

        if (! $this->customer) {
            return;
        }

        // Dapur bisa tutup ATAU istirahat mulai setelah keranjang terisi.
        if (! $this->kitchen->isOpen()) {
            $this->error = $this->kitchenNotice;

            return;
        }

        if ($this->cartTotal <= 0) {
            $this->error = 'Keranjang masih kosong.';

            return;
        }

        $this->confirm = 'order';
    }

    /**
     * Menagih & mencatat pesanan. Validasi diulang di action (jalur uang tidak
     * boleh percaya pemanggilnya), jadi di sini cukup menerjemahkan kegagalannya
     * jadi kalimat yang berguna — bukan 500.
     */
    public function placeOrder(): void
    {
        $this->error = null;

        if (! $this->customer) {
            return;
        }

        try {
            app(PlaceMenuOrderAction::class)->handle($this->customer, $this->unit, $this->cart);
        } catch (InsufficientBalanceException) {
            $this->confirm = null;
            $this->error = 'Saldo belum cukup untuk pesanan ini. Isi saldo dulu.';

            return;
        } catch (InvalidArgumentException $exception) {
            $this->confirm = null;
            $this->error = $exception->getMessage();
            $this->forgetCart();

            return;
        }

        $this->confirm = null;
        $this->cart = [];
        $this->notice = 'Pesanan diterima! Sedang disiapkan.';
        $this->forgetCart();
        unset($this->customer, $this->openOrders);
    }

    /**
     * Setiap pembelian lewat satu langkah konfirmasi — "yakin?" dengan rincian
     * & sisa saldo — sebelum uang benar-benar berpindah. askPlay() memvalidasi
     * lalu MEMBUKA konfirmasi; play() yang benar-benar menagih dipanggil dari
     * tombol di dalam konfirmasi itu. Validasi tetap diulang di play(): ia
     * jalur uang, dan tidak boleh bergantung pada pemanggil memvalidasi lebih
     * dulu.
     */
    public function askPlay(): void
    {
        $this->error = null;

        if (! $this->customer) {
            return;
        }

        if ($this->playChoice === '') {
            $this->error = 'Pilih paket atau Open Play dulu.';

            return;
        }

        // Open Play: main bebas, ditagih per menit. Diperiksa di sini supaya
        // pelanggan tahu lebih dulu kalau belum boleh berutang, bukan setelah
        // menekan "Ya".
        if ($this->playChoice === 'open') {
            if ($this->customer->balance <= 0 && ! $this->customer->isCreditEligible()) {
                $this->error = 'Isi saldo dulu sebelum Open Play.';

                return;
            }

            $this->confirm = 'open';

            return;
        }

        $this->packageId = (int) $this->playChoice;
        $this->confirm = 'play';
    }

    public function startOpenPlay(): void
    {
        $this->error = null;

        if (! $this->customer) {
            return;
        }

        try {
            app(StartKioskOpenPlayAction::class)->handle($this->customer, $this->unit, trim($this->voucherCode) ?: null);
        } catch (CreditNotAllowedException $exception) {
            $this->confirm = null;
            $this->error = $exception->getMessage();

            return;
        } catch (UnitAlreadyActiveException) {
            $this->confirm = null;
            $this->error = 'Unit ini baru saja dipakai orang lain.';

            return;
        } catch (DiscountNotApplicableException $e) {
            // Voucher tak berlaku — tetap di modal supaya pelanggan bisa hapus
            // kodenya & lanjut tanpa voucher.
            $this->error = $e->getMessage();

            return;
        }

        $this->confirm = null;
        $this->voucherCode = '';
        unset($this->activeSession);
    }

    /**
     * Berhenti Open Play. Menit pertama tetap ditagih: kalau belum lewat 60
     * detik, aksinya menolak dengan sisa waktu, bukan menghentikan sesi.
     */
    public function stopOpenPlay(): void
    {
        $this->error = null;

        $session = $this->activeSession;

        // Kepemilikan WAJIB dicek di SERVER, bukan cuma menyembunyikan tombol:
        // method publik Livewire bisa dipanggil langsung dari klien. Tanpa ini,
        // siapa pun yang membuka /kios/<unit> (bahkan anonim) bisa menghentikan
        // & menagih sesi Open Play pelanggan lain. customer_id sesi Open Play tak
        // pernah null, jadi pengunjung anonim (customer null) juga tertolak.
        if (! $session || $session->customer_id !== $this->customer?->id) {
            return;
        }

        try {
            app(StopKioskOpenPlayAction::class)->handle($session);
        } catch (SessionTooShortException $exception) {
            $this->error = $exception->getMessage();

            return;
        }

        // Segarkan user di guard: StopKioskOpenPlayAction menagih lewat instance
        // Customer yang dikunci TERPISAH, jadi user yang dipegang guard (yang
        // dikembalikan $this->customer) masih memegang saldo LAMA. Tanpa refresh
        // ini, cek "saldo minus" di bawah tak pernah true saat baru masuk utang,
        // dan kartu saldo sempat menampilkan angka positif basi satu render.
        Auth::guard('customer')->user()?->refresh();
        unset($this->activeSession, $this->customer);

        // Kalau berhenti meninggalkan saldo minus, langsung arahkan ke pelunasan
        // dengan nominal sudah terisi sebesar utangnya — pelanggan tidak perlu
        // mengetik ulang angka yang sistem sudah tahu.
        $fresh = $this->customer;

        if ($fresh && $fresh->balance < 0) {
            $this->tab = 'topup';
            $this->topUpAmount = abs($fresh->balance);
        }
    }

    public function cancelConfirm(): void
    {
        $this->confirm = null;
        $this->error = null;
        $this->voucherCode = '';
    }

    public function play(): void
    {
        $this->error = null;

        if (! $this->customer) {
            return;
        }

        $this->validate(
            ['packageId' => 'required|integer'],
            messages: ['packageId.required' => 'Pilih paket dulu sebelum mulai main.'],
            attributes: ['packageId' => 'paket'],
        );

        try {
            app(PlayFromWalletAction::class)->handle(
                $this->customer,
                $this->unit,
                Package::findOrFail($this->packageId),
                trim($this->voucherCode) ?: null,
            );
        } catch (InsufficientBalanceException) {
            $this->confirm = null;
            $this->error = 'Saldo belum cukup untuk paket ini. Isi saldo dulu.';

            return;
        } catch (UnitAlreadyActiveException) {
            $this->confirm = null;
            $this->error = 'Unit ini baru saja dipakai orang lain.';

            return;
        } catch (DiscountNotApplicableException $e) {
            // Voucher jadi tak berlaku antara pratinjau & tebus (mis. kuota habis
            // detik itu). Tetap di modal supaya pelanggan bisa hapus kodenya &
            // lanjut tanpa voucher.
            $this->error = $e->getMessage();

            return;
        } catch (InvalidArgumentException|ModelNotFoundException) {
            // Paket/unit/akun tak lagi valid — dinonaktifkan, DIHAPUS (Package
            // tanpa SoftDeletes, jadi findOrFail bisa melempar ModelNotFound),
            // atau playChoice di-tamper ke paket tipe unit lain. Dilempar SEBELUM
            // saldo dipotong, jadi aman; jangan biarkan jadi 500 ke pelanggan.
            $this->confirm = null;
            $this->error = 'Paket ini sedang tidak tersedia. Coba pilih lagi.';

            return;
        }

        $this->confirm = null;
        $this->voucherCode = '';
        unset($this->activeSession);
    }

    public function askTopUp(): void
    {
        $this->error = null;
        $this->validateTopUp();
        $this->confirm = 'topup';
    }

    private function validateTopUp(): void
    {
        $this->validate([
            'topUpAmount' => 'required|integer|min:'.OpenTopUpAction::MINIMUM.'|max:'.OpenTopUpAction::MAXIMUM,
            'method' => 'required|in:qris,transfer',
        ], messages: [
            'topUpAmount.required' => 'Masukkan nominal isi saldo dulu.',
            'topUpAmount.min' => 'Nominal minimal '.Rupiah::format(OpenTopUpAction::MINIMUM).'.',
            'topUpAmount.max' => 'Nominal maksimal '.Rupiah::format(OpenTopUpAction::MAXIMUM).'.',
            'method.required' => 'Pilih metode pembayaran dulu.',
        ], attributes: ['topUpAmount' => 'nominal', 'method' => 'metode pembayaran']);
    }

    public function topUp(): void
    {
        $this->error = null;
        $this->validateTopUp();

        try {
            ['payment' => $payment, 'qr_url' => $qrUrl] = app(OpenTopUpAction::class)->handle(
                $this->customer,
                (int) $this->topUpAmount,
                PaymentMethod::from($this->method),
                trim($this->voucherCode) ?: null,
            );
        } catch (DiscountNotApplicableException $e) {
            // Voucher tak berlaku — tetap di modal supaya pelanggan bisa hapus
            // kodenya & lanjut tanpa bonus.
            $this->error = $e->getMessage();

            return;
        } catch (Throwable $exception) {
            // Pesan mentah tidak pernah ditampilkan: isinya bisa memuat detail
            // gateway atau jalur berkas. Yang berguna bagi pelanggan hanyalah
            // langkah berikutnya.
            report($exception);
            $this->confirm = null;
            $this->error = 'Pembayaran sedang tidak bisa dibuat. Coba lagi sebentar, atau isi saldo lewat kasir.';

            return;
        }

        $this->confirm = null;
        $this->voucherCode = '';
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
        // Hanya menyegarkan bacaan. paymentId SENGAJA tidak direset saat lunas:
        // layar "Pembayaran berhasil" muncul dari status Paid, dan pelanggan
        // menutupnya sendiri lewat finishPayment() — supaya konfirmasi lunasnya
        // benar-benar terlihat, bukan berkedip lalu langsung hilang.
        unset($this->payment, $this->activeSession, $this->customer);
    }

    public function finishPayment(): void
    {
        $this->reset('paymentId', 'qrUrl', 'topUpAmount', 'method', 'notice');
        $this->tab = 'main';
    }

    /**
     * Pesan transien tidak boleh menyeberang antar tab: galat "pilih paket dulu"
     * dari tab Main tak berarti apa-apa di tab Isi saldo. Livewire memanggil ini
     * otomatis saat properti $tab berubah (termasuk lewat $set di quick-tile).
     */
    public function updatedTab(): void
    {
        $this->reset('error', 'notice');
    }

    /**
     * Jalan keluar dari layar pembayaran yang menggantung (QRIS/transfer belum
     * dibayar): tanpa ini pelanggan terkurung di layar itu sampai gateway
     * meng-Expired-kannya, satu-satunya escape reload penuh. Tagihannya ditandai
     * Expired supaya tidak ada QR hidup yang bisa terlanjur dibayar setelahnya.
     */
    public function cancelPayment(): void
    {
        $payment = $this->payment;

        // QRIS bisa TERLANJUR dibayar dalam ~10 dtk sebelum poll berikutnya
        // menandainya Lunas. Menghanguskannya begitu saja = pelanggan kehilangan
        // uang tanpa pemulihan: poll & reconcile hanya memproses Pending/Paid,
        // bukan Expired. Jadi tanya gateway dulu sebelum menghanguskan QRIS.
        if ($payment && $payment->status === PaymentStatus::Pending && $payment->method === PaymentMethod::Qris) {
            $status = app(MidtransGateway::class)->statusOf($payment);

            if ($status === PaymentStatus::Paid) {
                // Sudah dibayar → selesaikan (kredit saldo), JANGAN hanguskan.
                // Layar sukses muncul sendiri dari status Paid.
                app(SettleQrisPaymentAction::class)->handle($payment);
                unset($this->payment, $this->customer, $this->activeSession);

                return;
            }

            if ($status === null) {
                // Gateway tak terjangkau: bisa jadi sudah dibayar. JANGAN
                // hanguskan; biarkan Pending — poll & reconcile menuntaskannya.
                $this->error = 'Belum bisa dibatalkan — pembayaran masih dicek. Tunggu sebentar lalu coba lagi.';

                return;
            }

            // Gateway memastikan belum/tidak dibayar → aman dihanguskan (di bawah).
        }

        if ($payment && $payment->status === PaymentStatus::Pending) {
            $payment->update(['status' => PaymentStatus::Expired]);
        }

        $this->reset('paymentId', 'qrUrl', 'topUpAmount', 'method', 'notice', 'error');
        unset($this->payment);
        $this->tab = 'topup';
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

        // Dorong kasir: bukti butuh diverifikasi, dan tidak ada yang menatap
        // panel menunggunya.
        TransferProofSubmitted::dispatch($payment->id);

        $this->proof = null;
        unset($this->payment);
    }
};
?>

@php($sess = $this->activeSession)
@php($mine = $sess && $this->customer && $sess->customer_id === $this->customer->id)
{{-- Sedang main MILIK SENDIRI bukan lagi keadaan yang menggantikan dasbor.
     Pelanggan yang sudah main tetap butuh isi saldo dan pesan makanan; memaksanya
     berhenti dulu hanya untuk jajan berarti menghentikan tagihan yang sedang
     berjalan — merugikan pelanggan sekaligus outlet. --}}
@php($playing = (bool) $mine)
{{-- Layar pembayaran (lunas, menunggu verifikasi, QRIS, transfer) ditampilkan
     sebagai panel geser yang SUDAH terbuka, bukan kartu yang menumpuk di bawah
     kartu sesi. Kondisinya disalin persis dari rantai di bawah supaya keadaan
     pembayaran lain — tunai, gagal, kedaluwarsa — tetap jatuh ke dasbor seperti
     sebelumnya, bukan berakhir di panel kosong. --}}
@php($paymentScreen = $this->payment && (
    $this->payment->status === PaymentStatus::Paid
    || $this->payment->status === PaymentStatus::AwaitingVerification
    || ($this->payment->status === PaymentStatus::Pending
        && in_array($this->payment->method, [PaymentMethod::Qris, PaymentMethod::Transfer], true))
))
{{-- Rata tengah juga saat bermain: yang tersisa di layar hanya satu kartu
     sesi, dan kartu tunggal yang menempel di atas dengan ruang kosong sepanjang
     layar di bawahnya terbaca seperti halaman yang gagal memuat sisanya. --}}
@php($centered = ! $this->customer || $playing || ($sess && ! $mine))

<div class="kiosk {{ $centered ? 'kiosk--center' : '' }}">
    {{-- Langganan realtime kanal privat pelanggan (lihat kioskRealtime di layout).
         wire:ignore supaya Livewire tidak me-render ulang & menggandakan langganan
         tiap poll; muncul saat login, hilang (leave) saat logout. --}}
    @if ($this->customer)
        <div wire:ignore x-data="kioskRealtime({{ $this->customer->id }})"></div>
    @endif
    <div class="kiosk-head">
        <p class="kiosk-brand">Creative Trees</p>
        <h1 class="kiosk-unit">{{ $unit->code }}</h1>
        <p class="kiosk-type"><span class="pill">{{ $unit->unitType->name }}</span></p>
    </div>

    <div class="stack">
    @if ($playing && $sess->type === SessionType::Open)
            {{-- Open Play milik pelanggan ini: saldo hidup (turun per detik) +
                 tombol berhenti. Angkanya dihitung di sisi klien dari tarif &
                 waktu jalan — tagihan pastinya tetap dihitung server saat
                 berhenti (SessionTotal). --}}
            <div class="card" wire:poll.10s="refreshStatus"
                 x-data="{
                    started: new Date('{{ $sess->started_at->toIso8601String() }}'),
                    rate: {{ (int) $unit->unitType->hourly_rate }},
                    balance: {{ (int) $this->customer->balance }},
                    ceiling: {{ \App\Domain\Billing\OpenPlay::CREDIT_CEILING }},
                    elapsed: 0, stopping: false,
                    fmtRp(n) { return (n < 0 ? '−Rp ' : 'Rp ') + Math.abs(n).toLocaleString('id-ID'); },
                    fmtTime(s) { return [Math.floor(s/3600), Math.floor(s/60)%60, s%60].map(n => String(n).padStart(2,'0')).join(':'); },
                    get cost() { return Math.floor(this.elapsed * this.rate / 3600); },
                    get effective() { return this.balance - this.cost; },
                    tick() {
                        this.elapsed = Math.max(0, Math.floor((new Date() - this.started) / 1000));
                        // Plafon: paksa berhenti sebelum tembus batas minus. Ini
                        // penjaga sisi klien; backstop server menyusul (Fase 4).
                        if (! this.stopping && this.effective <= -this.ceiling) { this.stopping = true; $wire.stopOpenPlay(); }
                    }
                 }" x-init="tick(); setInterval(() => tick(), 1000)">
                <p class="label center">Sedang main · Open Play</p>
                <p class="amount" :class="effective < 0 ? 'neg' : ''" x-text="fmtRp(effective)">—</p>
                <p class="muted center">Sisa saldo · berjalan <span x-text="fmtTime(elapsed)">00:00:00</span></p>

                {{-- Jajan & isi saldo TANPA meninggalkan kartu sesi. Ditaruh di
                     sini, tepat di bawah waktu berjalan, karena inilah satu-satunya
                     tempat mata pelanggan sedang berada saat ia lapar. Menaruhnya
                     jauh di bawah halaman sama saja menyembunyikannya. --}}
                <div class="quick quick-inline">
                    @foreach ([['order', 'Pesan', 'heroicon-o-shopping-bag'], ['topup', 'Isi saldo', 'heroicon-o-plus'], ['history', 'Riwayat', 'heroicon-o-clock']] as [$qKey, $qLabel, $qIcon])
                        <button type="button" class="quick-tile"
                                wire:click="$set('tab', '{{ $qKey }}')"
                                x-on:click="$dispatch('kiosk-sheet')"
                                aria-label="{{ $qLabel }}" title="{{ $qLabel }}">
                            @svg($qIcon)
                        </button>
                    @endforeach
                </div>
                @if ($error) <p class="alert">{{ $error }}</p> @endif
                <button type="button" class="btn btn-block-gap" wire:click="stopOpenPlay"
                        wire:loading.attr="disabled" wire:target="stopOpenPlay"
                        :disabled="elapsed < 60"
                        x-text="elapsed < 60 ? ('Berhenti dalam ' + (60 - elapsed) + ' detik') : 'Berhenti & bayar'">Berhenti &amp; bayar</button>
            </div>

    @elseif ($playing && $sess->ends_at)
            {{-- Paket milik pelanggan ini: hitung mundur, tidak ada tombol —
                 waktunya sudah dibayar di muka. --}}
            <div class="card" wire:poll.10s="refreshStatus">
                <p class="label center">Sedang main</p>
                <p class="timer" x-data="{ display: '--:--:--' }"
                   x-init="const ends = new Date('{{ $sess->ends_at->toIso8601String() }}');
                       const tick = () => { const s = Math.max(0, Math.floor((ends - new Date())/1000));
                           display = [Math.floor(s/3600), Math.floor(s/60)%60, s%60].map(n => String(n).padStart(2,'0')).join(':'); };
                       tick(); setInterval(tick, 1000);"
                   x-text="display">--:--:--</p>
                <p class="muted center">Selamat bermain!</p>

                {{-- Jajan & isi saldo TANPA meninggalkan kartu sesi. Ditaruh di
                     sini, tepat di bawah waktu berjalan, karena inilah satu-satunya
                     tempat mata pelanggan sedang berada saat ia lapar. Menaruhnya
                     jauh di bawah halaman sama saja menyembunyikannya. --}}
                <div class="quick quick-inline">
                    @foreach ([['order', 'Pesan', 'heroicon-o-shopping-bag'], ['topup', 'Isi saldo', 'heroicon-o-plus'], ['history', 'Riwayat', 'heroicon-o-clock']] as [$qKey, $qLabel, $qIcon])
                        <button type="button" class="quick-tile"
                                wire:click="$set('tab', '{{ $qKey }}')"
                                x-on:click="$dispatch('kiosk-sheet')"
                                aria-label="{{ $qLabel }}" title="{{ $qLabel }}">
                            @svg($qIcon)
                        </button>
                    @endforeach
                </div>
            </div>

    @endif

    @if ($sess && ! $mine)
            {{-- Sesi orang lain (atau belum login): info saja. Dasbor memang
                 dihentikan di sini — unit ini bukan miliknya. --}}
            <div class="card" wire:poll.10s="refreshStatus">
                <p class="label center">Unit sedang dipakai</p>
                @if ($sess->ends_at)
                    <p class="timer" x-data="{ display: '--:--:--' }"
                       x-init="const ends = new Date('{{ $sess->ends_at->toIso8601String() }}');
                           const tick = () => { const s = Math.max(0, Math.floor((ends - new Date())/1000));
                               display = [Math.floor(s/3600), Math.floor(s/60)%60, s%60].map(n => String(n).padStart(2,'0')).join(':'); };
                           tick(); setInterval(tick, 1000);"
                       x-text="display">--:--:--</p>
                @endif
                <p class="muted center">Pindai lagi kode ini setelah unit selesai dipakai.</p>
            </div>

    @elseif (! $this->customer)
        {{-- LANGKAH 1 — nomor WhatsApp saja --}}
        @if ($step === 'phone')
            <div class="card">
                <h2 class="card-title">Masuk</h2>
                <p class="card-sub">Masukkan nomor WhatsApp untuk mulai main.</p>

                <form wire:submit="continueWithPhone">
                    <div class="field-affix">
                        <span class="prefix">@svg('heroicon-o-device-phone-mobile')</span>
                        <input type="tel" wire:model="phone" inputmode="numeric" autocomplete="tel"
                               placeholder="081234567890" class="field" autofocus required>
                    </div>

                    @if ($error) <p class="alert">{{ $error }}</p> @endif

                    <button type="submit" class="btn" wire:loading.attr="disabled" wire:target="continueWithPhone">
                        <span wire:loading.remove wire:target="continueWithPhone">Lanjut</span>
                        <span wire:loading wire:target="continueWithPhone"><span class="spin"></span> Memeriksa&hellip;</span>
                    </button>
                </form>
            </div>

        {{-- LANGKAH 2 — kode OTP dari WhatsApp --}}
        @elseif ($step === 'otp')
            <div class="card">
                <h2 class="card-title">Masukkan Kode</h2>
                <p class="card-sub">
                    Kode dikirim ke WhatsApp <strong>{{ $this->phone }}</strong>.
                    <button type="button" class="linkish" style="display:inline;width:auto;margin:0;padding:0" wire:click="editPhone"><b>Ganti nomor</b></button>
                </p>

                {{-- Verifikasi otomatis saat kotak terakhir terisi — pelanggan
                     tidak perlu menekan tombol apa pun kalau kodenya benar. --}}
                <x-kiosk.code-input model="code" submit="verifyOtp" autofocus
                                    wire-key="otp-{{ $otpSentAt }}" />

                @if ($error) <p class="alert">{{ $error }}</p> @endif

                <button type="button" class="btn" wire:click="verifyOtp" wire:loading.attr="disabled" wire:target="verifyOtp">
                    <span wire:loading.remove wire:target="verifyOtp">Verifikasi</span>
                    <span wire:loading wire:target="verifyOtp"><span class="spin"></span> Memeriksa&hellip;</span>
                </button>

                {{-- Hitung mundur nyata 60 detik sebelum boleh minta kode lagi. --}}
                <div class="resend" wire:key="resend-{{ $otpSentAt }}"
                     x-data="{ left: 60 }"
                     x-init="
                        const sent = new Date('{{ $otpSentAt }}');
                        const tick = () => { left = Math.max(0, 60 - Math.floor((new Date() - sent) / 1000)); };
                        tick(); const t = setInterval(() => { tick(); if (left === 0) clearInterval(t); }, 1000);
                     ">
                    <template x-if="left > 0">
                        <span>Kirim ulang dalam <span x-text="'00:' + String(left).padStart(2, '0')"></span></span>
                    </template>
                    <template x-if="left === 0">
                        <button type="button" wire:click="resendOtp">Kirim ulang kode</button>
                    </template>
                </div>

                <button type="button" class="linkish" wire:click="usePin">Tidak dapat kode? <b>Masuk dengan PIN</b></button>
            </div>

        {{-- Jalur cadangan — PIN pendaftaran --}}
        @elseif ($step === 'pin')
            <div class="card">
                <h2 class="card-title">Masuk dengan PIN</h2>
                <p class="card-sub">
                    Untuk nomor <strong>{{ $this->phone }}</strong>.
                    <button type="button" class="linkish" style="display:inline;width:auto;margin:0;padding:0" wire:click="editPhone"><b>Ganti nomor</b></button>
                </p>

                <form wire:submit="signInWithPin">
                    <x-kiosk.code-input model="pin" submit="signInWithPin" masked autofocus />

                    @if ($error) <p class="alert">{{ $error }}</p> @endif

                    <button type="submit" class="btn" wire:loading.attr="disabled" wire:target="signInWithPin">
                        <span wire:loading.remove wire:target="signInWithPin">Masuk</span>
                        <span wire:loading wire:target="signInWithPin"><span class="spin"></span> Memeriksa&hellip;</span>
                    </button>
                </form>

                @if ($otpSentAt)
                    <button type="button" class="linkish" wire:click="$set('step', 'otp')">Kembali ke kode OTP</button>
                @endif
            </div>

        {{-- Nomor belum terdaftar — daftar dulu --}}
        @elseif ($step === 'register')
            <div class="card">
                <h2 class="card-title">Buat Akun</h2>
                <p class="card-sub">
                    Nomor <strong>{{ $this->phone }}</strong> belum terdaftar.
                    <button type="button" class="linkish" style="display:inline;width:auto;margin:0;padding:0" wire:click="editPhone"><b>Ganti nomor</b></button>
                </p>

                <form wire:submit="register">
                    <input type="text" wire:model="name" maxlength="60" placeholder="Nama" class="field" autofocus required>

                    {{-- Tidak auto-kirim: namanya bisa masih kosong, dan
                         mengirim di angka ke-6 akan menolak pendaftarannya. --}}
                    <p class="field-label">Buat PIN 6 angka</p>
                    <x-kiosk.code-input model="pin" masked />

                    @if ($error) <p class="alert">{{ $error }}</p> @endif

                    <button type="submit" class="btn" wire:loading.attr="disabled" wire:target="register">
                        <span wire:loading.remove wire:target="register">Daftar &amp; lanjut</span>
                        <span wire:loading wire:target="register"><span class="spin"></span> Menyiapkan&hellip;</span>
                    </button>
                </form>
                <p class="muted center" style="margin-top:.75rem;font-size:.8rem">PIN ini dipakai kalau kode WhatsApp tidak sampai.</p>
            </div>
        @endif

    {{-- LUNAS — konfirmasi berhasil yang benar-benar terlihat, konsisten untuk
         QRIS maupun transfer, ditutup sendiri oleh pelanggan. --}}
    @elseif ($paymentScreen)
        {{-- is-open sejak dirender: pembayaran yang sedang menunggu bukan
             sesuatu yang harus dicari pelanggan. Ia muncul sendiri, dan
             tetap bisa ditutup lewat tombol di kepala panel. --}}
        <div @if ($playing) class="sheet is-open"
                 x-data="{ open: true }"
                 x-on:keydown.escape.window="open = false"
                 :class="{ 'is-open': open }" @endif>

        @if ($playing)
            <div class="sheet-head">
                <span class="sheet-grip" aria-hidden="true"></span>
                <button type="button" class="sheet-close" x-on:click="open = false" aria-label="Tutup">&times;</button>
            </div>
        @endif

        @if ($this->payment?->status === PaymentStatus::Paid)
        <div class="card pay-card">
            <div class="center"><span class="icon-badge icon-badge-ok">@svg('heroicon-o-check-circle')</span></div>
            <h2 class="card-title">Pembayaran berhasil</h2>
            <p class="card-sub">Saldo bertambah <strong>{{ Rupiah::format($this->payment->creditedAmount()) }}</strong>.</p>
            <button type="button" class="btn" wire:click="finishPayment">Selesai</button>
        </div>

    @elseif ($this->payment?->status === PaymentStatus::AwaitingVerification)
        <div class="card pay-card" wire:poll.3s="refreshStatus">
            <div class="center"><span class="icon-badge">@svg('heroicon-o-clock')</span></div>
            <h2 class="card-title">Menunggu kasir</h2>
            <p class="card-sub">Bukti sudah terkirim. Saldo bertambah begitu kasir memastikan uangnya masuk.</p>
        </div>

    @elseif ($this->payment?->status === PaymentStatus::Pending && $this->payment->method === PaymentMethod::Qris)
        <div class="card pay-card" wire:poll.3s="refreshStatus">
            <div class="center"><span class="icon-badge">@svg('heroicon-o-qr-code')</span></div>
            <h2 class="card-title">Bayar dengan QRIS</h2>
            <p class="pay-amount">{{ Rupiah::format($this->payment->amount) }}</p>
            @if ($this->payment->fee > 0)
                <p class="pay-fee">Termasuk biaya admin {{ Rupiah::format($this->payment->fee) }}</p>
            @endif
            @if ($qrUrl)
                <img src="{{ $qrUrl }}" alt="Kode QRIS" class="qr">
            @endif
            <p class="card-sub" style="margin:1rem 0 0">Pindai dengan aplikasi bank atau e-wallet. Layar ini berpindah sendiri setelah pembayaran masuk.</p>
            <button type="button" class="linkish" wire:click="cancelPayment" wire:confirm="Batalkan tagihan isi saldo ini?">Batalkan</button>
        </div>

    @elseif ($this->payment?->status === PaymentStatus::Pending && $this->payment->method === PaymentMethod::Transfer)
        <div class="card pay-card" wire:poll.3s="refreshStatus">
            <div class="center"><span class="icon-badge">@svg('heroicon-o-building-library')</span></div>
            <h2 class="card-title">Transfer ke rekening</h2>
            <p class="pay-amount">{{ Rupiah::format($this->payment->amount) }}</p>
            @if ($this->payment->fee > 0)
                <p class="pay-fee">Termasuk biaya admin {{ Rupiah::format($this->payment->fee) }}</p>
            @endif

            <div class="account">
                <p class="account-bank">{{ $this->transferAccount['bank'] }}</p>
                <p class="account-number">{{ $this->transferAccount['number'] }}</p>
                <p class="account-holder"><span>A/N</span> {{ $this->transferAccount['holder'] }}</p>
            </div>

            <p class="card-sub">Transfer <strong>persis</strong> sebesar nominal di atas, lalu unggah bukti transfernya.</p>

            <form wire:submit="uploadProof">
                {{-- Pemilih file modern: input asli disembunyikan, tombolnya kartu
                     berikon kamera yang menampilkan nama file setelah dipilih. --}}
                <label class="upload {{ $proof ? 'has-file' : '' }}">
                    <input type="file" wire:model="proof" accept="image/*">
                    <span class="upload-ic">@svg('heroicon-o-document-arrow-up')</span>
                    <span class="upload-text" wire:loading.remove wire:target="proof">
                        @if ($proof)
                            <b>Foto bukti terpilih</b>
                            <span class="upload-name">{{ \Illuminate\Support\Str::limit($proof->getClientOriginalName(), 28) }}</span>
                        @else
                            <b>Pilih foto bukti transfer</b>
                            <span class="upload-name">Ketuk untuk ambil / pilih dari galeri</span>
                        @endif
                    </span>
                    <span class="upload-text" wire:loading wire:target="proof"><b>Mengunggah&hellip;</b></span>
                </label>
                @error('proof') <p class="error">{{ $message }}</p> @enderror

                <button type="submit" class="btn btn-block-gap" wire:loading.attr="disabled" wire:target="uploadProof,proof" @disabled(! $proof)>
                    <span wire:loading.remove wire:target="uploadProof">Kirim bukti transfer</span>
                    <span wire:loading wire:target="uploadProof"><span class="spin"></span> Mengirim&hellip;</span>
                </button>
            </form>
            <button type="button" class="linkish" wire:click="cancelPayment" wire:confirm="Batalkan tagihan isi saldo ini?">Batalkan</button>
        </div>

        @endif
        </div>{{-- /panel pembayaran --}}

    @else
        {{-- Dihitung SEBELUM cabang mana pun: keduanya dipakai halaman dasar
             maupun panel geser. Sebelumnya keduanya lahir di dalam blok yang
             hanya berjalan saat tidak bermain, sehingga panel yang dibuka saat
             bermain meledak dengan "Undefined variable $activeTab".

             Saldo minus = akun TERKUNCI untuk main sampai dilunasi. Bukan flag
             terpisah: saldo negatif itu sendiri yang mengunci. --}}
        @php($locked = $this->customer->balance < 0)
        @php($activeTab = ($locked || $playing) && $tab === 'main' ? 'topup' : $tab)

        {{-- Saat bermain, kartu saldo tidak ditampilkan sama sekali. Kartu
             sesi di atasnya sudah memuat saldo yang berjalan per detik; dua
             kartu bertumpuk dengan dua angka yang berbeda beberapa rupiah —
             satu berjalan, satu diam — membuat pelanggan ragu mana yang benar. --}}
        @unless ($playing)
        {{-- DASBOR — kartu saldo paling atas: saldonya satu-satunya angka yang
             menentukan apakah pelanggan bisa langsung main atau harus isi dulu.
             Dikemas seperti kartu pembayaran; nomor kartunya tersamar (hanya 4
             karakter terakhir), persis kartu sungguhan. --}}
        <div class="card balance-card">
            <div class="vc-top">
                <span class="vc-brand">Creative Trees</span>
                <span class="vc-wifi">@svg('heroicon-o-wifi')</span>
            </div>
            <span class="vc-chip"></span>
            <p class="vc-label">Saldo</p>
            {{-- Ukuran font mengecil mengikuti panjang angka supaya saldo besar
                 tetap satu baris di posisi yang sama (bukan turun & merusak
                 kartu). min(rem, vw): rem membatasi di layar lebar, vw ikut
                 mengecil di HP sempit. --}}
            @php($balanceText = Rupiah::format($this->customer->balance))
            @php($balanceSize = match (true) {
                mb_strlen($balanceText) <= 12 => 'min(2.5rem, 9vw)',
                mb_strlen($balanceText) <= 14 => 'min(2.15rem, 8vw)',
                mb_strlen($balanceText) <= 16 => 'min(1.85rem, 7vw)',
                mb_strlen($balanceText) <= 18 => 'min(1.6rem, 6vw)',
                default => 'min(1.35rem, 5.2vw)',
            })
            <p class="vc-balance {{ $this->customer->balance < 0 ? 'neg' : '' }}" style="font-size: {{ $balanceSize }}">{{ $balanceText }}</p>
            <p class="vc-number">{{ $this->customer->maskedCardNumber() }}</p>
            <div class="vc-bottom">
                <p class="vc-name">{{ $this->customer->name }}</p>
                <div class="vc-meta">
                    <span><span class="k">CVC</span><span class="v">{{ $this->customer->cardCvc() }}</span></span>
                    <span><span class="k">Exp</span><span class="v">&#8734;</span></span>
                </div>
            </div>
        </div>
        @if ($notice) <p class="notice">{{ $notice }}</p> @endif

        @if ($locked)
            <p class="alert">Saldo minus <b>−{{ Rupiah::format(abs($this->customer->balance)) }}</b>. Lunasi dulu untuk bisa main lagi.</p>
        @endif

        {{-- Menu cepat: tombol bulat ikon-saja, tekan satu → bagiannya muncul di
             bawah. aria-label wajib karena tidak ada teks.

             Saat bermain, "Main" DIBUANG, bukan ditampilkan sebagai tombol mati.
             Tombol mati tetap meminta perhatian dan tetap ditekan orang, lalu
             tidak terjadi apa-apa — pelanggan mengira aplikasinya rusak. Sisanya
             tinggal tiga, sama persis dengan baris di dalam kartu sesi, jadi
             kedua tempat itu tidak lagi menawarkan pilihan yang berbeda. --}}
        @php($quickTiles = collect([
            ['main', 'Main', 'heroicon-o-play'],
            ['order', 'Pesan', 'heroicon-o-shopping-bag'],
            ['topup', 'Isi saldo', 'heroicon-o-plus'],
            ['history', 'Riwayat', 'heroicon-o-clock'],
        ])->reject(fn (array $tile): bool => $playing && $tile[0] === 'main'))
        <div class="quick">
            @foreach ($quickTiles as [$key, $labelText, $icon])
                @php($disabled = $locked && in_array($key, ['main', 'order'], true))
                {{-- Menekan tile TIDAK cukup mengganti tab: isi Pesan, Isi saldo,
                     dan Riwayat kini hidup di dalam panel geser yang tersembunyi
                     sampai dibuka. Tanpa dispatch di bawah, tab berpindah tetapi
                     panelnya tetap tertutup — pelanggan menekan tombol lalu
                     menatap halaman KOSONG, dan tidak ada pesan apa pun yang
                     menjelaskan mengapa. "Main" dikecualikan: isinya memang
                     berada di halaman, bukan di panel. --}}
                <button type="button" class="quick-tile {{ $activeTab === $key ? 'is-active' : '' }} {{ $disabled ? 'quick-off' : '' }}"
                        @if ($disabled) disabled @else wire:click="$set('tab', '{{ $key }}')" @endif
                        @if ($playing && ! $disabled && $key !== 'main') x-on:click="$dispatch('kiosk-sheet')" @endif
                        aria-label="{{ $labelText }}" title="{{ $labelText }}">
                    @svg($icon)
                </button>
            @endforeach
        </div>

        @if ($activeTab === 'main')
        <div class="card">
            <p class="label">Pilih cara main</p>

            {{-- Open Play — mode BEBAS, dipisah dari paket berdurasi tetap sebagai
                 satu kartu hero dengan ikon. Sorotannya hanya muncul saat dipilih
                 (:has(input:checked)), tidak lagi selalu bernuansa cognac. --}}
            <label class="play-open" wire:key="pc-open">
                <input type="radio" wire:model="playChoice" value="open">
                <span class="po-ic">@svg('heroicon-o-bolt')</span>
                <span class="po-body">
                    <span class="po-title">Open Play</span>
                    <span class="po-sub">Main bebas, bayar per menit</span>
                </span>
                <span class="po-rate">{{ Rupiah::format($unit->unitType->hourly_rate) }} <span class="per">/ jam</span></span>
            </label>

            <div class="play-divider">atau pilih paket</div>

            <div class="play-grid">
                @foreach ($this->packages->forPage($packagePage, 4) as $package)
                    @php($terjangkau = $this->customer->canAfford($package->price))
                    <label class="play-pkg {{ $terjangkau ? '' : 'play-off' }}" wire:key="pkg-{{ $package->id }}">
                        <input type="radio" wire:model="playChoice" value="{{ $package->id }}" @disabled(! $terjangkau)>
                        <span class="pg-dur">{{ $package->name }}</span>
                        <span class="pg-min">{{ $package->duration_minutes }} menit @unless ($terjangkau)&middot; saldo kurang @endunless</span>
                        <span class="pg-price">{{ Rupiah::format($package->price) }}</span>
                    </label>
                @endforeach
            </div>

            {{-- Pager paket muncul hanya kalau paketnya lebih dari 4. --}}
            @if ($this->packages->count() > 4)
                <div class="pager">
                    <button type="button" class="pager-btn" wire:click="packagePrev" @disabled($packagePage <= 1) aria-label="Sebelumnya">@svg('heroicon-o-chevron-left')</button>
                    <span class="pager-info">Hal {{ $packagePage }} / {{ $this->packagePageCount() }}</span>
                    <button type="button" class="pager-btn" wire:click="packageNext" @disabled($packagePage >= $this->packagePageCount()) aria-label="Berikutnya">@svg('heroicon-o-chevron-right')</button>
                </div>
            @endif
            @if ($error) <p class="alert">{{ $error }}</p> @endif

            <button type="button" class="btn" wire:click="askPlay" wire:loading.attr="disabled" wire:target="askPlay">Mulai main</button>
        </div>
        @endif
        @endunless

        {{-- Panel geser: Pesan, Isi saldo, dan Riwayat SELALU naik dari bawah,
             baik sedang bermain maupun tidak. Sebelumnya ketiganya tampil
             menurun di halaman saat tidak bermain dan sebagai panel saat
             bermain — satu aplikasi dengan dua perilaku untuk tombol yang sama.

             "Pilih cara main" sengaja TIDAK ikut ke dalam panel: itu langkah
             pertama pelanggan baru, dan menyembunyikannya di balik satu tekanan
             tombol menambah satu langkah tepat sebelum ia membayar. --}}
        {{-- Panel geser HANYA saat bermain. Di luar sesi, isi tab tampil
             menurun di halaman seperti semula: layar sedang lapang, tidak ada
             yang perlu dilindungi dari tertutup, dan menyembunyikan isinya di
             balik panel hanya menambah satu tekanan tombol tanpa imbalan.
             Saat bermain keadaannya berbeda — kartu sesi dengan saldo berjalan
             dan tombol berhenti harus tetap terlihat, dan panel satu-satunya
             cara memberi ruang penuh tanpa mengusirnya dari layar. --}}
        <div @if ($playing) class="sheet"
                 x-data="{ open: false }"
                 x-on:kiosk-sheet.window="open = true"
                 x-on:keydown.escape.window="open = false"
                 :class="{ 'is-open': open }" @endif>

        @if ($playing)
            <div class="sheet-head">
                <span class="sheet-grip" aria-hidden="true"></span>
                <button type="button" class="sheet-close" x-on:click="open = false" aria-label="Tutup">&times;</button>
            </div>
        @endif

            @if ($playing)
            {{-- Tiga saja: "Main" tidak pernah ikut ke panel. Panel ini isinya
                 Pesan, Isi saldo, dan Riwayat; menaruh "Main" di sini berarti
                 menawarkan mulai bermain dari tempat yang justru dibuka karena
                 pelanggan sedang tidak ingin memulai apa pun. --}}
            <div class="quick quick-inline">
                @foreach ([['order', 'Pesan', 'heroicon-o-shopping-bag'], ['topup', 'Isi saldo', 'heroicon-o-plus'], ['history', 'Riwayat', 'heroicon-o-clock']] as [$sKey, $sLabel, $sIcon])
                    <button type="button"
                            class="quick-tile {{ $activeTab === $sKey ? 'is-active' : '' }} {{ $locked && $sKey === 'order' ? 'quick-off' : '' }}"
                            @if ($locked && $sKey === 'order') disabled @else wire:click="$set('tab', '{{ $sKey }}')" @endif
                            aria-label="{{ $sLabel }}" title="{{ $sLabel }}">
                        @svg($sIcon)
                    </button>
                @endforeach
            </div>
            @endif

        @if ($activeTab === 'order')
        <div class="card">
            <p class="label">Pesan makanan &amp; minuman</p>

            {{-- Dapur tutup/istirahat: beri tahu DAN sebutkan jamnya. Menu
                 sengaja tidak dirender sama sekali — memperlihatkan pilihan
                 yang tak bisa dipesan cuma memindahkan kekecewaan ke satu
                 ketukan berikutnya. --}}
            @if (! $this->kitchen->isOpen())
                <div class="kitchen-shut kitchen-shut--{{ $this->kitchen->value }}" role="status">
                    <span class="kitchen-shut-icon">
                        @svg($this->kitchen === MenuServiceStatus::Break ? 'heroicon-o-clock' : 'heroicon-o-moon')
                    </span>
                    <p class="kitchen-shut-title">{{ $this->kitchen->getLabel() }}</p>
                    <p class="kitchen-shut-note">{{ $this->kitchenNotice }}</p>
                </div>
            @else

            @forelse ($this->menu as $category)
                <div class="menu-sec" wire:key="cat-{{ $category->id }}">
                    <div class="menu-sec-head">
                        <span class="menu-sec-name">{{ $category->name }}</span>
                        <span class="menu-sec-rule"></span>
                        <span class="menu-sec-count">{{ $category->items->count() }} pilihan</span>
                    </div>

                    @foreach ($category->items as $item)
                        @php($qty = (int) ($cart[$item->id] ?? 0))
                        <div class="menu-item {{ $qty > 0 ? 'is-picked' : '' }}" wire:key="mi-{{ $item->id }}">
                            <div class="menu-item-body">
                                <div class="menu-item-name">{{ $item->name }}</div>
                                <div class="menu-item-price">{{ Rupiah::format($item->price) }}</div>
                            </div>
                            <div class="stepper">
                                @if ($qty > 0)
                                    <button type="button" class="step-btn" wire:click="removeFromCart({{ $item->id }})" aria-label="Kurangi {{ $item->name }}">&minus;</button>
                                    <span class="step-qty" aria-live="polite" aria-label="{{ $qty }} {{ $item->name }}">{{ $qty }}</span>
                                @endif
                                <button type="button" class="step-btn step-add" wire:click="addToCart({{ $item->id }})" aria-label="Tambah {{ $item->name }}">+</button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @empty
                <p class="menu-empty">Belum ada menu di sini.<br>Minta kasir menambahkannya.</p>
            @endforelse

            @endif

            @if ($error) <p class="alert">{{ $error }}</p> @endif

            @if ($this->openOrders->isNotEmpty())
                <div class="menu-sec">
                    <div class="menu-sec-head">
                        <span class="menu-sec-name">Sedang berjalan</span>
                        <span class="menu-sec-rule"></span>
                    </div>
                    @foreach ($this->openOrders as $order)
                        <div class="order-live" wire:key="oo-{{ $order->id }}">
                            <span class="order-dot"></span>
                            <div class="order-live-body">
                                <div class="order-live-items">{{ $order->summary() }}</div>
                                <div class="order-live-state">{{ $order->status->getLabel() }}</div>
                            </div>
                            <span class="order-live-total">{{ Rupiah::format($order->total_amount) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Baki diletakkan TERAKHIR supaya sticky-nya melayang di atas
                 seluruh isi kartu saat digulir, bukan berhenti di tengah. --}}
            @if ($this->cartTotal > 0 && $this->kitchen->isOpen())
                @php($cartCount = array_sum(array_column($this->cartLines, 'quantity')))
                <div class="tray">
                    <div class="tray-body">
                        <div class="tray-count">{{ $cartCount }} item di keranjang</div>
                        <div class="tray-total">{{ Rupiah::format($this->cartTotal) }}</div>
                    </div>
                    <button type="button" class="tray-btn" wire:click="askOrder" wire:loading.attr="disabled" wire:target="askOrder">Pesan sekarang</button>
                </div>
            @endif
        </div>
        @elseif ($activeTab === 'topup')
        <div class="card">
            <p class="label">{{ $locked ? 'Lunasi saldo minus' : 'Isi saldo' }}</p>

            {{-- Nominal DIKETIK manual lewat papan angka, bukan pilihan tetap.
                 Sumber kebenarannya properti Livewire topUpAmount — BUKAN state
                 Alpine terpisah — supaya angka yang sudah diketik tidak hilang
                 saat komponen re-render (mis. setelah galat "minimal 10.000").
                 $wire.set(..., false) menunda kiriman ke server sampai tombol
                 ditekan, jadi tidak ada network per ketukan. --}}
            <div x-data="{
                    get show() { const v = $wire.topUpAmount; return 'Rp ' + (v ? Number(v).toLocaleString('id-ID') : '0'); },
                    push(d) { const n = (String($wire.topUpAmount ?? '') + d).replace(/^0+/, ''); if (n && Number(n) <= {{ OpenTopUpAction::MAXIMUM }}) $wire.set('topUpAmount', Number(n), false); },
                    del() { const n = String($wire.topUpAmount ?? '').slice(0, -1); $wire.set('topUpAmount', n ? Number(n) : null, false); }
                 }">
                <p class="pad-amount" x-text="show">Rp 0</p>
                <p class="muted center" style="margin:-.5rem 0 1rem;font-size:.8rem">Minimal {{ Rupiah::format(OpenTopUpAction::MINIMUM) }} · Maks {{ Rupiah::format(OpenTopUpAction::MAXIMUM) }}</p>
                <div class="pad">
                    @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '000', '0', 'del'] as $k)
                        @if ($k === 'del')
                            <button type="button" class="pad-key" @click="del()" aria-label="Hapus angka">@svg('heroicon-o-backspace')</button>
                        @else
                            <button type="button" class="pad-key" @click="push('{{ $k }}')">{{ $k }}</button>
                        @endif
                    @endforeach
                </div>
            </div>
            @error('topUpAmount') <p class="error">{{ $message }}</p> @enderror

            {{-- Metode bayar: tombol BULAT + ikon, label di bawah. --}}
            <div class="methods-round">
                @foreach ($this->availableMethods as $available)
                    <label class="method-r" wire:key="m-{{ $available->value }}">
                        <input type="radio" wire:model="method" value="{{ $available->value }}">
                        <span class="ic">@svg($available === PaymentMethod::Qris ? 'heroicon-o-qr-code' : 'heroicon-o-building-library')</span>
                        {{ $available->getLabel() }}
                    </label>
                @endforeach
            </div>
            @error('method') <p class="error">{{ $message }}</p> @enderror
            @if ($error) <p class="alert">{{ $error }}</p> @endif

            <button type="button" class="btn btn-block-gap" wire:click="askTopUp" wire:loading.attr="disabled" wire:target="askTopUp">Lanjut</button>
        </div>
        {{-- @elseif eksplisit, BUKAN @else. Rantai ini sekarang dimulai dari
             'order', jadi @else akan ikut menangkap tab 'main' — panel diam-diam
             merender riwayat lengkap dengan query-nya di layar pilih-cara-main,
             dan pelanggan yang membuka panel menemukan riwayat, bukan yang ia
             tekan. --}}
        @elseif ($activeTab === 'history')
        <div class="card">
            <div class="section-title">
                <h3>Transaksi</h3>
                <span class="perpage">
                    Tampilkan
                    <input type="number" min="1" max="50" inputmode="numeric"
                           wire:model.live.debounce.400ms="perPage" class="perpage-input" aria-label="Transaksi per halaman">
                </span>
            </div>

            @forelse ($this->transactions as $tx)
                @php($masuk = $tx->amount > 0)
                @php($metode = $tx->payment?->method)
                <div class="tx" wire:key="tx-{{ $tx->id }}">
                    <span class="tx-icon {{ $masuk ? 'tx-in' : 'tx-out' }}">
                        @svg($masuk ? 'heroicon-o-arrow-down-left' : 'heroicon-o-arrow-up-right')
                    </span>
                    <div class="tx-body">
                        <p class="tx-title">
                            {{ $tx->type->getLabel() }}
                            {{-- Metode bayar pemasukan: dari mana uangnya masuk. --}}
                            @if ($metode)
                                <span class="tx-badge">{{ $metode->getLabel() }}</span>
                            @endif
                        </p>
                        <p class="tx-sub">{{ $tx->description }}</p>
                    </div>
                    <div class="tx-right">
                        <span class="tx-amt {{ $masuk ? 'in' : '' }}">{{ $masuk ? '+' : '−' }}{{ Rupiah::format(abs($tx->amount)) }}</span>
                        <span class="tx-time">{{ $tx->created_at->translatedFormat('d M, H:i') }}</span>
                    </div>
                </div>
            @empty
                <p class="tx-empty">Belum ada transaksi.</p>
            @endforelse

            {{-- Pager digambar tangan: view pagination bawaan Livewire memakai
                 kelas Tailwind yang tidak terkompilasi di proyek nol-build ini. --}}
            @if ($this->transactions->hasPages())
                <div class="pager">
                    <button type="button" class="pager-btn" wire:click="previousPage" @disabled($this->transactions->onFirstPage()) aria-label="Sebelumnya">@svg('heroicon-o-chevron-left')</button>
                    <span class="pager-info">Hal {{ $this->transactions->currentPage() }} / {{ $this->transactions->lastPage() }}</span>
                    <button type="button" class="pager-btn" wire:click="nextPage" @disabled(! $this->transactions->hasMorePages()) aria-label="Berikutnya">@svg('heroicon-o-chevron-right')</button>
                </div>
            @endif
        </div>
        @endif

        {{-- Penutup pembungkus panel geser, DI DALAM cabang yang sama tempat
             ia dibuka. Sebelumnya penutup ini tersesat ke dalam
             @elseif ($confirm === 'topup'), sehingga hanya terender saat modal
             top-up kebetulan terbuka; di keadaan lain root komponen tidak
             pernah ditutup. Livewire lalu menolak memasang komponennya
             ("missing closing tags found") dan setiap wire:click sesudahnya
             gagal dengan "Cannot read properties of undefined (reading 'uri')"
             -- tombol tampak hidup, tetapi tidak ada yang terjadi saat ditekan.

             Menaruhnya di luar semua cabang juga salah: pembungkus ini hanya
             dibuka untuk pelanggan yang sudah masuk, jadi bagi tamu penutupnya
             akan menutup sesuatu yang tidak pernah ada. --}}
        </div>{{-- /pembungkus panel geser --}}

        {{-- Keluar berada di halaman, bukan di dalam panel: menutup sesi masuk
             bukan bagian dari memesan atau mengisi saldo, dan menyembunyikannya
             di balik panel membuat pelanggan tidak menemukan cara keluar. --}}
        <button type="button" class="linkish" wire:click="signOut">Keluar</button>
    @endif
    </div>

    @unless ($centered)
        <p class="foot">Sesi baru berjalan setelah pembayaran diterima.</p>
    @endunless

    {{-- Konfirmasi setiap pembelian: uang tidak berpindah sampai pelanggan
         menekan "Ya". Ditampilkan sebagai sheet menutupi seluruh layar supaya
         tidak ada yang bisa disentuh di belakangnya secara tak sengaja. --}}
    @if ($confirm === 'play')
        @php($pkg = $this->packages->firstWhere('id', $packageId))
        @php($vr = $this->voucherResult)
        @php($vrOk = $vr && ($vr['ok'] ?? false))
        @php($charge = $vrOk ? $vr['final'] : (int) $pkg?->price)
        <div class="modal-backdrop">
            <div class="modal">
                <div class="center"><span class="icon-badge">@svg('heroicon-o-play')</span></div>
                <h2 class="card-title">Yakin mulai main?</h2>
                <p class="card-sub">Di unit <strong>{{ $unit->code }}</strong></p>
                <div class="confirm-rows">
                    <div><span>Paket</span><b>{{ $pkg?->name }}</b></div>
                    <div><span>Durasi</span><b>{{ $pkg?->duration_minutes }} menit</b></div>
                    <div><span>Harga</span><b>{{ Rupiah::format((int) $pkg?->price) }}</b></div>
                    @if ($vrOk)
                        <div><span>Voucher</span><b style="color:var(--ok)">− {{ Rupiah::format($vr['discount']) }}</b></div>
                    @endif
                    <div class="confirm-total"><span>Sisa saldo nanti</span><b>{{ Rupiah::format($this->customer->balance - $charge) }}</b></div>
                </div>

                {{-- Voucher: diketik lalu lepas fokus (blur) untuk pratinjau —
                     tanpa network per ketukan. Kode dipotong ulang di server saat
                     menebus (kuota di bawah kunci). --}}
                <input type="text" wire:model.blur="voucherCode" placeholder="Kode voucher, mis. 9X3X-6TPC"
                       class="field" style="text-transform:uppercase" autocomplete="off" maxlength="30">
                @if ($vr && ! $vrOk)
                    <p class="error">{{ $vr['message'] }}</p>
                @elseif ($vrOk)
                    <p class="notice" style="margin-top:.5rem">Voucher "{{ $vr['label'] }}" dipakai.</p>
                @endif

                <button type="button" class="btn btn-block-gap" wire:click="play" wire:loading.attr="disabled" wire:target="play">
                    <span wire:loading.remove wire:target="play">Ya, mulai main</span>
                    <span wire:loading wire:target="play"><span class="spin"></span> Menyalakan TV&hellip;</span>
                </button>
                <button type="button" class="btn btn-ghost btn-block-gap" wire:click="cancelConfirm">Batal</button>
            </div>
        </div>
    @elseif ($confirm === 'open')
        <div class="modal-backdrop">
            <div class="modal">
                <div class="center"><span class="icon-badge">@svg('heroicon-o-bolt')</span></div>
                <h2 class="card-title">Mulai Open Play?</h2>
                <p class="card-sub">Main bebas di <strong>{{ $unit->code }}</strong></p>
                <div class="confirm-rows">
                    <div><span>Tarif</span><b>{{ Rupiah::format($unit->unitType->hourly_rate) }} / jam</b></div>
                    <div class="confirm-total"><span>Saldo sekarang</span><b>{{ Rupiah::format($this->customer->balance) }}</b></div>
                </div>
                <p class="muted center" style="font-size:.8rem;margin:.25rem 0 0">Saldo kepakai jalan. Kalau habis, sisanya jadi utang yang wajib dilunasi. Minimal main 1 menit.</p>

                {{-- Voucher Open Play: potongannya ke tagihan saat berhenti, jadi
                     di sini hanya divalidasi kodenya. --}}
                @php($ovr = $this->openVoucherResult)
                <input type="text" wire:model.blur="voucherCode" placeholder="Kode voucher, mis. 9X3X-6TPC"
                       class="field" style="text-transform:uppercase;margin-top:.75rem" autocomplete="off" maxlength="30">
                @if ($ovr && ! ($ovr['ok'] ?? false))
                    <p class="error">{{ $ovr['message'] }}</p>
                @elseif ($ovr && ($ovr['ok'] ?? false))
                    <p class="notice" style="margin-top:.5rem">Voucher "{{ $ovr['label'] }}" dipakai — potongan muncul saat berhenti.</p>
                @endif

                <button type="button" class="btn btn-block-gap" wire:click="startOpenPlay" wire:loading.attr="disabled" wire:target="startOpenPlay">
                    <span wire:loading.remove wire:target="startOpenPlay">Ya, mulai main</span>
                    <span wire:loading wire:target="startOpenPlay"><span class="spin"></span> Menyalakan TV&hellip;</span>
                </button>
                <button type="button" class="btn btn-ghost btn-block-gap" wire:click="cancelConfirm">Batal</button>
            </div>
        </div>
    @elseif ($confirm === 'order')
        <div class="modal-backdrop">
            <div class="modal">
                <div class="center"><span class="icon-badge">@svg('heroicon-o-shopping-bag')</span></div>
                <h2 class="card-title">Yakin pesan?</h2>
                <p class="card-sub">Diantar ke <b>{{ $unit->code }}</b></p>
                <div class="confirm-rows">
                    @foreach ($this->cartLines as $line)
                        <div wire:key="cl-{{ $line['item']->id }}">
                            <span>{{ $line['item']->name }} x{{ $line['quantity'] }}</span>
                            <b>{{ Rupiah::format($line['subtotal']) }}</b>
                        </div>
                    @endforeach
                    <div class="confirm-total"><span>Total</span><b>{{ Rupiah::format($this->cartTotal) }}</b></div>
                    <div><span>Sisa saldo nanti</span><b>{{ Rupiah::format($this->customer->balance - $this->cartTotal) }}</b></div>
                </div>
                <button type="button" class="btn btn-block-gap" wire:click="placeOrder" wire:loading.attr="disabled" wire:target="placeOrder">
                    <span wire:loading.remove wire:target="placeOrder">Ya, pesan</span>
                    <span wire:loading wire:target="placeOrder"><span class="spin"></span> Memproses&hellip;</span>
                </button>
                <button type="button" class="btn btn-ghost btn-block-gap" wire:click="cancelConfirm">Batal</button>
            </div>
        </div>
    @elseif ($confirm === 'topup')
        @php($tvr = $this->topUpVoucherResult)
        @php($tvrOk = $tvr && ($tvr['ok'] ?? false))
        <div class="modal-backdrop">
            <div class="modal">
                <div class="center"><span class="icon-badge">@svg('heroicon-o-plus')</span></div>
                <h2 class="card-title">Yakin isi saldo?</h2>
                @php($fee = $this->topUpFee)
                <div class="confirm-rows">
                    <div><span>Isi saldo</span><b>{{ Rupiah::format((int) $topUpAmount) }}</b></div>
                    <div><span>Metode</span><b>{{ $method ? PaymentMethod::from($method)->getLabel() : '' }}</b></div>
                    @if ($fee > 0)
                        <div><span>Biaya admin</span><b>+ {{ Rupiah::format($fee) }}</b></div>
                        <div class="confirm-total"><span>Total bayar</span><b>{{ Rupiah::format((int) $topUpAmount + $fee) }}</b></div>
                    @endif
                    @if ($tvrOk)
                        <div><span>Bonus voucher</span><b style="color:var(--ok)">+ {{ Rupiah::format($tvr['bonus']) }}</b></div>
                        <div class="confirm-total"><span>Saldo bertambah</span><b>{{ Rupiah::format((int) $topUpAmount + $tvr['bonus']) }}</b></div>
                    @endif
                </div>

                {{-- Voucher isi saldo: bonus saldo (bayar penuh, saldo bertambah
                     lebih). Bonusnya dikreditkan saat pembayaran lunas. --}}
                <input type="text" wire:model.blur="voucherCode" placeholder="Kode voucher, mis. 9X3X-6TPC"
                       class="field" style="text-transform:uppercase" autocomplete="off" maxlength="30">
                @if ($tvr && ! $tvrOk)
                    <p class="error">{{ $tvr['message'] }}</p>
                @elseif ($tvrOk)
                    <p class="notice" style="margin-top:.5rem">Voucher "{{ $tvr['label'] }}" dipakai.</p>
                @endif

                <button type="button" class="btn btn-block-gap" wire:click="topUp" wire:loading.attr="disabled" wire:target="topUp">
                    <span wire:loading.remove wire:target="topUp">Ya, lanjut bayar</span>
                    <span wire:loading wire:target="topUp"><span class="spin"></span> Menyiapkan&hellip;</span>
                </button>
                <button type="button" class="btn btn-ghost btn-block-gap" wire:click="cancelConfirm">Batal</button>
            </div>
        </div>
    @endif
</div>
