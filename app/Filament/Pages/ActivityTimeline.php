<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Models\Customer;
use App\Models\User;
use Devletes\FilamentTimelineView\Tables\Columns\TimelineEntry;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * Riwayat perbuatan, dibaca sebagai lini masa.
 *
 * Jejaknya sudah lama dicatat (activity_log) — yang belum ada adalah tempat
 * untuk MEMBACANYA. Tabel biasa memaksa mata melompat antar kolom untuk
 * menyusun urutan kejadian, padahal pertanyaan yang dibawa orang ke halaman
 * ini selalu berbentuk waktu: "apa yang terjadi sebelum saldo itu berubah?"
 *
 * Dipisah internal vs eksternal karena keduanya ditanyakan dalam situasi yang
 * berbeda: internal saat menelusuri tindakan staf, eksternal saat pelanggan
 * mempersoalkan saldonya sendiri.
 */
class ActivityTimeline extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Sistem;

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.activity-timeline';

    public static function getNavigationIcon(): string|\BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedClipboardDocumentList;
    }

    public static function getNavigationLabel(): string
    {
        return 'Aktivitas';
    }

    public function getTitle(): string
    {
        return 'Aktivitas';
    }

    /**
     * Membaca jejak berarti melihat seluruh perbuatan orang lain — hak yang
     * setara dengan mengelola pengguna, jadi digerbangi izin yang sama.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->checkPermissionTo('ViewAny:User') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Activity::query()->with(['causer', 'subject']))
            ->columns([
                TimelineEntry::make()
                    ->title(fn (Activity $record): string => $this->sentence($record))
                    // Pelaku dipindah ke author, judulnya diisi PERBUATANNYA.
                    // Lini masa dibaca menurun untuk mencari kejadian, bukan
                    // orang — daftar yang judulnya nama membuat lima baris
                    // berturut-turut terlihat identik.
                    ->author(fn (Activity $record): string => $this->actorName($record))
                    ->time(fn (Activity $record): string => $record->created_at->format('H:i'))
                    ->content(fn (Activity $record): string => $this->changes($record)),
            ])
            ->filters([
                SelectFilter::make('pelaku')
                    ->label('Pelaku')
                    ->options([
                        'internal' => 'Internal — staf outlet',
                        'external' => 'Eksternal — pelanggan kios',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'internal' => $query->where('causer_type', User::class),
                        // Perbuatan pelanggan sebagian tercatat TANPA causer
                        // (dilakukan sistem atas namanya, mis. sesi kios yang
                        // habis sendiri), jadi subjeknya ikut dihitung —
                        // kalau tidak, separuh riwayatnya hilang dari layar.
                        'external' => $query->where(fn (Builder $q) => $q
                            ->where('causer_type', Customer::class)
                            ->orWhere('subject_type', Customer::class)),
                        default => $query,
                    }),
            ])
            // Dikelompokkan per hari: pertanyaan yang dibawa ke layar ini
            // selalu "hari itu terjadi apa saja", dan tanpa pengelompokan
            // pembacanya harus membandingkan tanggal baris demi baris.
            ->groups([
                Group::make('created_at')
                    ->label('Tanggal')
                    ->date()
                    ->collapsible(),
            ])
            ->defaultGroup('created_at')
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100])
            ->asTimeline();
    }

    private function actorName(Activity $record): string
    {
        return match (true) {
            $record->causer instanceof User => $record->causer->name.' — staf',
            $record->causer instanceof Customer => $record->causer->name.' — pelanggan',
            // Bukan "tidak diketahui": tak adanya pelaku BERARTI sesuatu, yaitu
            // sistem yang bertindak sendiri (job terjadwal, sapuan, expiry).
            default => 'Sistem',
        };
    }

    /**
     * Isi perubahannya, bukan sekadar bahwa ada perubahan.
     *
     * activity_log menyimpan properti lama & baru, dan justru itulah yang
     * dicari orang saat menelusuri sengketa saldo — "berubah dari berapa ke
     * berapa". Tanpa ini lini masa cuma memberi tahu bahwa sesuatu terjadi,
     * lalu pembacanya tetap harus membuka tabel lain untuk tahu apa.
     */
    private function changes(Activity $record): string
    {
        $props = collect($record->properties ?? []);
        $old = collect($props->get('old') ?? []);
        $new = collect($props->get('attributes') ?? []);

        $lines = $new
            ->filter(fn ($value, string $key): bool => $old->get($key) !== $value)
            ->map(fn ($value, string $key): string => sprintf(
                '%s: %s → %s',
                $key,
                $this->readable($old->get($key)),
                $this->readable($value),
            ));

        if ($lines->isNotEmpty()) {
            return $lines->take(4)->implode(' · ');
        }

        // Perubahan saldo dicatat sebagai properti datar, bukan old/attributes.
        return $props
            ->except(['old', 'attributes'])
            ->map(fn ($value, string $key): string => $key.': '.$this->readable($value))
            ->take(4)
            ->implode(' · ');
    }

    private function readable(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'ya' : 'tidak',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE) ?: '—',
            default => (string) $value,
        };
    }

    /** Satu kalimat yang bisa dibaca tanpa tahu nama tabel. */
    private function sentence(Activity $record): string
    {
        $subject = match (true) {
            $record->subject instanceof Customer => 'pelanggan '.$record->subject->name,
            $record->subject === null => null,
            default => class_basename($record->subject_type).' #'.$record->subject_id,
        };

        return trim($record->description.($subject ? ' · '.$subject : ''));
    }
}
