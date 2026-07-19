<?php

namespace App\Filament\Resources\Settings\Pages;

use App\Filament\Resources\Settings\SettingResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Tanpa DeletesFromFormFooter, tidak seperti resource lain: setting TIDAK
 * pernah bisa dihapus. Setting::get('billing_increment_minutes') dipakai di
 * jalur penagihan Open Play, jadi baris yang hilang membuat perhitungan
 * diam-diam jatuh ke nilai default — tagihan berubah tanpa ada yang tahu.
 *
 * SettingPolicy::delete() yang menegakkannya; trait itu sengaja tidak dipakai
 * di sini supaya tidak terbaca seolah penghapusan cuma "dipindah tempatnya".
 */
class EditSetting extends EditRecord
{
    protected static string $resource = SettingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
