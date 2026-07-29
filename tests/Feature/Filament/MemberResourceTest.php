<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Wallet\Actions\SettleCashTopUpAction;
use App\Domain\Wallet\Wallet;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Customer;
use App\Models\User;
use App\Policies\CustomerPolicy;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/**
 * Isi saldo tunai adalah satu-satunya cara saldo bertambah dari panel — jalur
 * uang, jadi diuji ketat: saldo naik, pembayaran tunai tercatat Lunas dengan
 * nama kasirnya, dan buku besar tetap cocok dengan saldo.
 */
test('a cash top-up credits the wallet and records a paid cash payment', function () {
    $cashier = User::factory()->create();
    $customer = Customer::factory()->withBalance(0)->create();

    app(SettleCashTopUpAction::class)->handle($customer, 50_000, $cashier);

    expect($customer->fresh()->balance)->toBe(50_000)
        ->and($customer->fresh()->ledgerBalance())->toBe(50_000);

    $payment = $customer->payments()->sole();
    expect($payment->method)->toBe(PaymentMethod::Cash)
        ->and($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->verified_by)->toBe($cashier->id);
});

/**
 * Isi saldo dari panel TIDAK berplafon: operator tepercaya menerima uang tunai
 * sungguhan, jadi nominal besar pun sah. (Plafon anti-typo ada di sisi mandiri
 * kios, bukan di sini.)
 */
test('a panel cash top-up has no upper limit', function () {
    $cashier = User::factory()->create();
    $customer = Customer::factory()->withBalance(0)->create();

    app(SettleCashTopUpAction::class)->handle($customer, 25_000_000, $cashier);

    expect($customer->fresh()->balance)->toBe(25_000_000)
        ->and($customer->fresh()->ledgerBalance())->toBe(25_000_000);
});

/**
 * Kegunaan utama resource ini: memastikan seseorang terdaftar dengan mencari
 * nomornya.
 */
test('the member list finds a registered customer by phone', function () {
    $owner = User::factory()->owner()->create();
    Customer::factory()->create(['name' => 'Budi', 'phone' => '081234567890']);
    Customer::factory()->create(['name' => 'Sari', 'phone' => '081200000000']);

    Livewire::actingAs($owner)->test(ListCustomers::class)
        ->searchTable('081234567890')
        ->assertCanSeeTableRecords(Customer::where('phone', '081234567890')->get())
        ->assertCanNotSeeTableRecords(Customer::where('phone', '081200000000')->get());
});

test('a cashier tops up a member with cash from the panel', function () {
    $kasir = User::factory()->create();
    $customer = Customer::factory()->withBalance(0)->create();

    Livewire::actingAs($kasir)->test(ListCustomers::class)
        ->mountAction(TestAction::make('cashTopUp')->table($customer))
        ->setActionData(['amount' => 30_000])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($customer->fresh()->balance)->toBe(30_000);
});

/**
 * Koreksi manual owner boleh menjadikan saldo MINUS dengan sengaja — mis.
 * membebankan utang. Kurangi 5jt dari saldo 1jt → −4jt, dan buku besar tetap
 * cocok.
 */
test('an owner can adjust a balance into the negative', function () {
    $owner = User::factory()->owner()->create();
    $customer = Customer::factory()->create();
    // Saldo awal lewat top-up sungguhan supaya buku besar ikut tercatat —
    // baru koreksi manualnya bisa dibandingkan apel-ke-apel dengan saldo.
    app(Wallet::class)->topUp($customer, 1_000_000);

    Livewire::actingAs($owner)->test(ListCustomers::class)
        ->mountAction(TestAction::make('adjustBalance')->table($customer))
        ->setActionData(['direction' => 'subtract', 'amount' => 5_000_000, 'reason' => 'Bebankan utang'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($customer->fresh()->balance)->toBe(-4_000_000)
        ->and($customer->fresh()->ledgerBalance())->toBe(-4_000_000);
});

/**
 * Koreksi saldo — apalagi yang bisa membuat minus — bukan wewenang kasir.
 */
test('a cashier cannot adjust a balance', function () {
    $kasir = User::factory()->create();
    $customer = Customer::factory()->withBalance(100_000)->create();

    Livewire::actingAs($kasir)->test(ListCustomers::class)
        ->assertActionHidden(TestAction::make('adjustBalance')->table($customer));

    expect(app(CustomerPolicy::class)->adjustBalance($kasir, $customer))->toBeFalse();
});

/**
 * Reset PIN dari form: mengetik PIN baru mengganti pin_hash, dan membuka lalu
 * menyimpan TANPA mengisi PIN tidak menyentuhnya (pola dehydrated-when-filled).
 */
test('resetting the PIN from the form updates the hash, and leaving it blank keeps it', function () {
    $owner = User::factory()->owner()->create();
    $customer = Customer::factory()->create();
    $originalHash = $customer->pin_hash;

    Livewire::actingAs($owner)->test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->fillForm(['name' => 'Nama Baru'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($customer->fresh()->pin_hash)->toBe($originalHash)
        ->and($customer->fresh()->name)->toBe('Nama Baru');

    Livewire::actingAs($owner)->test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->fillForm(['pin_hash' => '246810'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('246810', $customer->fresh()->pin_hash))->toBeTrue();
});

/**
 * Member tidak dibuat maupun dihapus dari panel: mereka mendaftar sendiri di
 * kios, dan menghapusnya berarti menghapus buku besar saldo.
 */
test('members cannot be created or deleted from the panel', function () {
    $user = User::factory()->create();

    expect(CustomerResource::getPages())->not->toHaveKey('create')
        ->and(app(CustomerPolicy::class)->create($user))->toBeFalse()
        ->and(app(CustomerPolicy::class)->delete($user, Customer::factory()->create()))->toBeFalse();
});
