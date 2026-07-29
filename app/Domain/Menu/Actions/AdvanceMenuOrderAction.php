<?php

namespace App\Domain\Menu\Actions;

use App\Domain\Menu\MenuOrderStatus;
use App\Models\MenuOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Memajukan pengerjaan pesanan: Baru → Disiapkan → Diantar.
 *
 * Dikunci di dalam transaksi supaya dua staf yang menekan tombol bersamaan
 * tidak melompati satu tahap atau saling menimpa.
 */
class AdvanceMenuOrderAction
{
    public function handle(MenuOrder $order, User $staff): MenuOrder
    {
        return DB::transaction(function () use ($order, $staff): MenuOrder {
            $locked = MenuOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $next = match ($locked->status) {
                MenuOrderStatus::Placed => MenuOrderStatus::Preparing,
                MenuOrderStatus::Preparing => MenuOrderStatus::Delivered,
                default => null,
            };

            if ($next === null) {
                throw new InvalidArgumentException('Pesanan ini sudah selesai atau dibatalkan.');
            }

            $locked->update(['status' => $next, 'handled_by' => $staff->id]);

            return $locked->fresh();
        });
    }
}
