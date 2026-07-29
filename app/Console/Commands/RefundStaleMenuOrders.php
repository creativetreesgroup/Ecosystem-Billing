<?php

namespace App\Console\Commands;

use App\Domain\Menu\Actions\CancelMenuOrderAction;
use App\Domain\Menu\MenuOrderStatus;
use App\Domain\Settings\SettingKey;
use App\Models\MenuOrder;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Jaring pengaman "sudah bayar, makanannya tidak pernah datang".
 *
 * Saldo pesanan jajanan ditarik sejak pesanan masuk (Placed) — itu benar, kalau
 * tidak, dapur bisa mengerjakan pesanan yang uangnya belum ada. Tapi artinya
 * pesanan yang TIDAK PERNAH disentuh siapa pun — sistem mati, tablet dapur
 * ketinggalan, outlet keburu tutup — meninggalkan pelanggan yang sudah membayar
 * makanan yang tak pernah ada, tanpa apa pun yang mengembalikannya.
 *
 * Yang disapu HANYA status Placed. Preparing berarti seorang staf sudah
 * memegang pesanan itu; membatalkannya otomatis membuat dapur memasak makanan
 * yang uangnya sudah dikembalikan.
 */
#[Signature('menu:refund-stale-orders')]
#[Description('Batalkan & kembalikan saldo pesanan makanan yang tak pernah disentuh staf')]
class RefundStaleMenuOrders extends Command
{
    public function handle(CancelMenuOrderAction $cancelOrder): int
    {
        $minutes = (int) Setting::get(SettingKey::StaleOrderRefundMinutes);

        // 0 = sengaja dimatikan pemilik outlet; pesanan terbengkalai lalu jadi
        // urusan manual, dan itu pilihan yang sah.
        if ($minutes <= 0) {
            return self::SUCCESS;
        }

        $stale = MenuOrder::query()
            ->where('status', MenuOrderStatus::Placed)
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->get();

        $refunded = 0;

        foreach ($stale as $order) {
            try {
                // Aksi yang sama dengan tombol Batalkan milik staf: pemeriksaan
                // status dan pengembaliannya ada di bawah satu kunci baris, jadi
                // sapuan ini tidak bisa berlomba dengan staf yang menekan
                // Batalkan pada detik yang sama.
                $cancelOrder->handle($order, User::kioskOperator());
                $refunded++;
            } catch (Throwable $e) {
                // Satu pesanan bermasalah tidak boleh menahan pengembalian
                // pesanan pelanggan lain.
                Log::error('Gagal mengembalikan saldo pesanan terbengkalai.', [
                    'menu_order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($refunded > 0) {
            $this->info("{$refunded} pesanan terbengkalai dibatalkan dan saldonya dikembalikan.");
        }

        return self::SUCCESS;
    }
}
