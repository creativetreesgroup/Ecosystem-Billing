<?php

namespace App\Domain\Menu\Actions;

use App\Domain\Menu\MenuOrderStatus;
use App\Domain\Wallet\Wallet;
use App\Models\MenuOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Membatalkan pesanan yang belum diantar dan MENGEMBALIKAN uangnya.
 *
 * Pemeriksaan status dan pengembaliannya berada di dalam satu kunci baris:
 * tanpa itu, dua klik "Batalkan" yang berbarengan bisa mengembalikan uang dua
 * kali untuk satu pesanan.
 */
class CancelMenuOrderAction
{
    public function __construct(private readonly Wallet $wallet) {}

    public function handle(MenuOrder $order, User $staff): MenuOrder
    {
        return DB::transaction(function () use ($order, $staff): MenuOrder {
            $locked = MenuOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isRefundable()) {
                throw new InvalidArgumentException('Pesanan yang sudah diantar atau dibatalkan tidak bisa dibatalkan lagi.');
            }

            $this->wallet->refundOrder($locked->customer, $locked->total_amount, $locked, $staff);

            $locked->update(['status' => MenuOrderStatus::Cancelled, 'handled_by' => $staff->id]);

            return $locked->fresh();
        });
    }
}
