<?php

namespace App\Domain\Menu\Actions;

use App\Domain\Menu\MenuOrderStatus;
use App\Domain\Menu\MenuServiceHours;
use App\Domain\Wallet\Wallet;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\MenuOrder;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Memesan jajanan dari kios.
 *
 * Pesanan dan penarikan saldonya berada di SATU transaksi. Kalau dipisah,
 * kegagalan di antaranya meninggalkan salah satu dari dua keadaan yang sama
 * buruknya: pesanan masuk dapur tanpa dibayar, atau saldo terpotong tanpa
 * pesanan. Harga SELALU dibaca ulang dari database di dalam transaksi —
 * tidak pernah dari layar pelanggan, yang bisa dipalsukan.
 */
class PlaceMenuOrderAction
{
    /** Batas wajar per baris; menahan ketikan iseng "99999" jadi tagihan absurd. */
    public const MAX_QUANTITY_PER_ITEM = 99;

    public function __construct(private readonly Wallet $wallet) {}

    /**
     * @param  array<int|string, int|string>  $quantities  [menu_item_id => jumlah]
     */
    public function handle(Customer $customer, Unit $unit, array $quantities): MenuOrder
    {
        // Menyembunyikan menu di layar BUKAN penutupan: tab kios cuma UI, dan
        // request Livewire-nya bisa dipanggil langsung. Kalau larangannya hanya
        // di tampilan, dapur tetap menerima pesanan pukul tiga pagi dan saldo
        // pelanggan tetap tertarik untuk makanan yang tak akan pernah dibuat.
        if (! MenuServiceHours::isOpen()) {
            throw new InvalidArgumentException((string) MenuServiceHours::notice());
        }

        $wanted = [];

        foreach ($quantities as $itemId => $quantity) {
            $quantity = (int) $quantity;

            if ($quantity <= 0) {
                continue;
            }

            if ($quantity > self::MAX_QUANTITY_PER_ITEM) {
                throw new InvalidArgumentException('Jumlah per item maksimal '.self::MAX_QUANTITY_PER_ITEM.'.');
            }

            $wanted[(int) $itemId] = $quantity;
        }

        if ($wanted === []) {
            throw new InvalidArgumentException('Belum ada item yang dipilih.');
        }

        return DB::transaction(function () use ($customer, $unit, $wanted): MenuOrder {
            $items = MenuItem::query()
                ->whereIn('id', array_keys($wanted))
                ->where('is_active', true)
                ->get();

            // Item yang dinonaktifkan (habis) di antara membuka menu dan menekan
            // pesan tidak boleh diam-diam dilewati — pelanggan harus tahu.
            if ($items->count() !== count($wanted)) {
                throw new InvalidArgumentException('Ada item yang sudah tidak tersedia. Silakan pilih ulang.');
            }

            $total = $items->sum(fn (MenuItem $item): int => $item->price * $wanted[$item->id]);

            $order = MenuOrder::create([
                'customer_id' => $customer->id,
                'unit_id' => $unit->id,
                'status' => MenuOrderStatus::Placed,
                'total_amount' => $total,
            ]);

            foreach ($items as $item) {
                $quantity = $wanted[$item->id];

                // Nama & harga DISALIN: struk harus tetap benar walau menunya
                // diubah atau dihapus setelah pesanan ini dibayar.
                $order->items()->create([
                    'menu_item_id' => $item->id,
                    'name' => $item->name,
                    'unit_price' => $item->price,
                    'quantity' => $quantity,
                    'line_total' => $item->price * $quantity,
                ]);
            }

            // Saldo ditarik di dalam transaksi yang sama: saldo kurang →
            // InsufficientBalanceException → pesanannya ikut batal.
            $this->wallet->spendOnOrder($customer, $total, $order);

            return $order->load('items');
        });
    }
}
