<?php

use App\Domain\Customers\Exceptions\TooManyPinAttemptsException;
use App\Domain\Customers\Otp\OtpChannel;
use App\Domain\Customers\Otp\OtpService;
use App\Models\OtpCode;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Penyalur palsu yang MENYIMPAN kodenya, supaya test bisa memakainya tanpa
 * pernah membuat kode itu terbaca di tempat yang bisa dilihat pengguna.
 */
function otpChannelSpy(): object
{
    $channel = new class implements OtpChannel
    {
        public array $sent = [];

        public function send(string $phone, string $code): bool
        {
            $this->sent[] = ['phone' => $phone, 'code' => $code];

            return true;
        }

        public function name(): string
        {
            return 'uji';
        }

        public function isConfigured(): bool
        {
            return true;
        }
    };

    app()->instance(OtpChannel::class, $channel);

    return $channel;
}

beforeEach(function () {
    $this->channel = otpChannelSpy();
    $this->otp = app(OtpService::class);
    RateLimiter::clear('otp-request:081234567890');
});

test('a code is sent to the normalised number', function () {
    $this->otp->request('+62 812-3456-7890');

    expect($this->channel->sent[0]['phone'])->toBe('081234567890')
        ->and($this->channel->sent[0]['code'])->toMatch('/^\d{6}$/');
});

test('the right code signs the customer in', function () {
    $this->otp->request('081234567890');

    expect($this->otp->verify('0812 3456 7890', $this->channel->sent[0]['code']))
        ->toBe('081234567890');
});

/**
 * Kode sekali pakai yang bisa dipakai dua kali bukan kode sekali pakai.
 * Orang di belakang antrean yang sempat melihat layar tidak boleh bisa memakai
 * kode yang sama semenit kemudian.
 */
test('a code cannot be used twice', function () {
    $this->otp->request('081234567890');
    $code = $this->channel->sent[0]['code'];

    $this->otp->verify('081234567890', $code);

    expect(fn () => $this->otp->verify('081234567890', $code))
        ->toThrow(ValidationException::class);
});

/**
 * Dump database tidak boleh cukup untuk masuk ke akun siapa pun.
 */
test('the code is never stored in the open', function () {
    $this->otp->request('081234567890');
    $code = $this->channel->sent[0]['code'];

    $row = OtpCode::query()->where('phone', '081234567890')->sole();

    expect($row->getRawOriginal('code_hash'))->not->toBe($code)
        ->and($row->toArray())->not->toHaveKey('code_hash');
});

test('an expired code is refused', function () {
    $this->otp->request('081234567890');
    $code = $this->channel->sent[0]['code'];

    OtpCode::query()->where('phone', '081234567890')->update(['expires_at' => now()->subMinute()]);

    expect(fn () => $this->otp->verify('081234567890', $code))
        ->toThrow(ValidationException::class);
});

test('guessing the code is cut off after a handful of tries', function () {
    $this->otp->request('081234567890');
    $benar = $this->channel->sent[0]['code'];

    foreach (range(1, 5) as $percobaan) {
        try {
            $this->otp->verify('081234567890', '000000');
        } catch (ValidationException) {
            //
        }
    }

    // Kode yang BENAR pun ditolak — kalau tidak, penebak tinggal menunggu
    // percobaan berikutnya berhasil.
    expect(fn () => $this->otp->verify('081234567890', $benar))
        ->toThrow(ValidationException::class);
});

/**
 * Membiarkan beberapa kode hidup sekaligus memperbesar peluang tebakan
 * berkali lipat, dan membuat "kode mana yang benar" jadi pertanyaan tanpa
 * jawaban saat pelanggan mengeluh.
 */
test('asking for a new code kills the previous one', function () {
    $this->otp->request('081234567890');
    $lama = $this->channel->sent[0]['code'];

    $this->otp->request('081234567890');

    expect(fn () => $this->otp->verify('081234567890', $lama))
        ->toThrow(ValidationException::class);

    expect($this->otp->verify('081234567890', $this->channel->sent[1]['code']))
        ->toBe('081234567890');
});

/**
 * Tiap permintaan MENGIRIM PESAN BERBAYAR ke HP orang sungguhan. Tanpa batas,
 * siapa pun bisa membuat HP orang lain berdering terus-menerus hanya dengan
 * mengetik nomornya — dan tagihan pesannya ditanggung outlet.
 */
test('requests are capped so nobody can be spammed or billed', function () {
    foreach (range(1, 3) as $permintaan) {
        $this->otp->request('081234567890');
    }

    expect(fn () => $this->otp->request('081234567890'))
        ->toThrow(TooManyPinAttemptsException::class);

    expect($this->channel->sent)->toHaveCount(3);
});

test('a number that is not a number never reaches the gateway', function () {
    expect(fn () => $this->otp->request('bukan-nomor'))->toThrow(ValidationException::class);

    expect($this->channel->sent)->toBeEmpty();
});

/**
 * Pengiriman gagal (internet mati, kuota gateway habis) bukan kejadian luar
 * biasa — alur harus tahu bahwa kodenya tidak sampai, bukan meledak.
 */
test('a failed send is reported, not thrown', function () {
    app()->instance(OtpChannel::class, new class implements OtpChannel
    {
        public function send(string $phone, string $code): bool
        {
            return false;
        }

        public function name(): string
        {
            return 'mati';
        }

        public function isConfigured(): bool
        {
            return false;
        }
    });

    expect(app(OtpService::class)->request('081234567890')['sent'])->toBeFalse();
});
