<?php

use App\Domain\Users\PermissionGroup;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;

/**
 * Grup adalah janji: "Admin Keuangan bisa apa saja DI KEUANGAN, dan tidak
 * menyentuh yang lain." Janji itu hanya bernilai kalau ada yang mengujinya —
 * kebocoran izin tidak menimbulkan galat, ia cuma diam-diam memberi orang
 * akses yang tak pernah dimaksudkan.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('the seeder builds a super admin plus two levels in every group', function () {
    expect(Role::where('name', 'super_admin')->exists())->toBeTrue();

    foreach (PermissionGroup::cases() as $group) {
        expect(Role::where('name', 'admin_'.$group->value)->exists())->toBeTrue()
            ->and(Role::where('name', 'staf_'.$group->value)->exists())->toBeTrue();
    }
});

test('a group admin holds every permission of its group and none outside it', function () {
    $admin = Role::where('name', 'admin_keuangan')->sole();

    $held = $admin->permissions->pluck('name');
    $expected = PermissionGroup::Keuangan->permissionNames();

    expect($held->sort()->values()->all())->toBe($expected->sort()->values()->all())
        ->and($held)->toContain('Delete:Payment')
        ->and($held)->not->toContain('ViewAny:User')
        ->and($held)->not->toContain('ViewAny:Unit');
});

/** Staf mengerjakan; yang menghapus ditahan di tingkat admin. */
test('staff get the working permissions but never the destructive ones', function () {
    $staff = Role::where('name', 'staf_keuangan')->sole();
    $held = $staff->permissions->pluck('name');

    expect($held)->toContain('ViewAny:Payment')
        ->and($held)->toContain('Verify:Payment')     // pekerjaan hariannya
        ->and($held)->not->toContain('Delete:Payment')
        ->and($held)->not->toContain('Update:Payment');
});

/**
 * Sifat yang membedakan "memimpin departemen" dari "kebetulan punya banyak
 * centang": izin yang muncul belakangan otomatis ikut terpegang.
 */
test('a newly added permission flows to the group admin on save', function () {
    $admin = Role::where('name', 'admin_sistem')->sole();
    $before = $admin->permissions()->count();

    Permission::create(['name' => 'Approve:Setting', 'guard_name' => 'web']);

    $admin->save();

    expect($admin->fresh()->permissions()->count())->toBe($before + 1)
        ->and($admin->fresh()->permissions->pluck('name'))->toContain('Approve:Setting');
});

test('a user only gets what their role carries', function () {
    $kasir = User::factory()->create();
    $kasir->assignRole('staf_operasional');

    expect($kasir->can('ViewAny:MenuOrder'))->toBeTrue()
        ->and($kasir->can('Delete:MenuOrder'))->toBeFalse()
        // Tabel sesi rental tetap khusus admin: kasir bekerja dari grid unit.
        ->and($kasir->can('ViewAny:RentalSession'))->toBeFalse()
        ->and($kasir->can('ViewAny:User'))->toBeFalse()
        ->and($kasir->can('ViewAny:Payment'))->toBeFalse();
});

test('the super admin reaches across every group', function () {
    $owner = User::factory()->create();
    $owner->assignRole('super_admin');

    expect($owner->can('ViewAny:Payment'))->toBeTrue()
        ->and($owner->can('ViewAny:User'))->toBeTrue()
        ->and($owner->can('ViewAny:Unit'))->toBeTrue()
        ->and($owner->can('Delete:Discount'))->toBeTrue();
});

/**
 * KRITIS (uang): beberapa aturan BUKAN soal peran, melainkan invarian.
 * Pembayaran harus lahir dari sesi atau dari pembayaran pelanggan — baris yang
 * diketik tangan adalah pemasukan tanpa apa pun yang menjelaskannya, dan
 * laporan pendapatan berhenti bisa direkonsiliasi. Tidak ada centang, tidak ada
 * peran, dan tidak ada super admin yang boleh membukanya.
 */
test('not even the super admin can hand-type a payment or delete one', function () {
    $owner = User::factory()->create();
    $owner->assignRole('super_admin');

    $payment = Payment::factory()->create();

    expect($owner->can('create', Payment::class))->toBeFalse()
        ->and($owner->can('delete', $payment))->toBeFalse()
        ->and($owner->can('update', $payment))->toBeFalse();
});

test('not even the super admin can delete a setting', function () {
    $owner = User::factory()->create();
    $owner->assignRole('super_admin');

    $setting = Setting::query()->first() ?? Setting::factory()->create();

    expect($owner->can('delete', $setting))->toBeFalse()
        ->and($owner->can('create', Setting::class))->toBeFalse();
});
