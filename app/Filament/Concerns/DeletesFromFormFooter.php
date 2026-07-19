<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;

/**
 * Memindahkan tombol Hapus dari header halaman ke footer form.
 *
 * Di header, Hapus berdiri sendirian di atas — terlihat seperti aksi utama
 * halaman, padahal ia yang paling merusak. Di layar HP posisinya bahkan tepat
 * di bawah jempol saat halaman baru dibuka.
 *
 * Di footer ia berada di deret yang sama dengan Simpan & Batal, tetapi paling
 * kanan: jauh dari Simpan, dan baru terjangkau setelah pengguna menggulir
 * melewati seluruh form yang sedang ia sunting.
 */
trait DeletesFromFormFooter
{
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            DeleteAction::make(),
        ];
    }
}
