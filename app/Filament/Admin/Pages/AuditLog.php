<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Filters\DateRangeFilter;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Filament\Shared\Tables\TableHeading;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Bank;
use App\Support\PlatformAudit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Sistem → Riwayat perubahan (admin): siapa mengubah setelan platform (pembayaran langganan, Midtrans, Turnstile,
 * pendaftaran, keamanan) dan master Bank, nilai sebelum → sesudah. Rahasia hanya "diganti". Hanya baca.
 */
final class AuditLog extends Page implements HasTable
{
    use HasIconBreadcrumbs;
    use InteractsWithTable;

    protected string $view = 'filament.admin.pages.audit-log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?string $navigationLabel = 'Riwayat perubahan';

    protected static ?string $title = 'Riwayat perubahan';

    protected static ?string $slug = 'audit';

    protected static ?int $navigationSort = 6;

    /**
     * Tanpa #[Url(as: 'filters')]: filter disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    public function getSubheading(): string
    {
        return 'Perubahan setelan platform & master bank oleh tim gs.POS. Nilai rahasia tidak pernah disimpan';
    }

    public function table(Table $table): Table
    {
        $table = $table
            ->query(fn (): Builder => PlatformAudit::query()->with('causer'))
            ->heading(TableHeading::make('Daftar perubahan', Heroicon::OutlinedClipboardDocumentList))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('j M Y')->timezone(FilamentTimezone::get())->sortable()
                    ->description(fn (ActivityLog $record): string => (string) $record->created_at?->setTimezone(FilamentTimezone::get())->format('H.i')),
                TextColumn::make('causer_name')->label('Oleh')->weight('medium')
                    ->state(fn (ActivityLog $record): string => $record->causer instanceof Admin ? $record->causer->name : 'Sistem')
                    ->description(fn (ActivityLog $record): string => $record->causer instanceof Admin ? (string) $record->causer->email : 'Otomatis'),
                TextColumn::make('subject')->label('Data')->weight('medium')->wrap()
                    ->state(fn (ActivityLog $record): string => self::subjectName($record))
                    ->description(fn (ActivityLog $record): string => self::group($record)),
                TextColumn::make('event')->label('Aksi')->badge()
                    ->state(fn (ActivityLog $record): string => self::eventLabel($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Diisi', 'Dibuat' => 'success',
                        'Dihapus' => 'danger',
                        default => 'info',
                    }),
                TextColumn::make('field')->label('Kolom')->listWithLineBreaks()->extraAttributes(['class' => 'gs-diff-cell is-field'])
                    ->state(fn (ActivityLog $record): array => self::column($record, 'label'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereRaw('LOWER(CAST(properties AS CHAR)) LIKE ?', ['%'.mb_strtolower($search).'%'])),
                TextColumn::make('old')->label('Sebelum')->listWithLineBreaks()->extraAttributes(['class' => 'gs-diff-cell is-old'])
                    ->state(fn (ActivityLog $record): array => self::column($record, 'old')),
                TextColumn::make('new')->label('Sesudah')->listWithLineBreaks()->extraAttributes(['class' => 'gs-diff-cell is-new'])
                    ->state(fn (ActivityLog $record): array => self::column($record, 'new')),
            ])
            ->filters([
                DateRangeFilter::make('created_at', defaultToday: false),
                SelectFilter::make('menu')->label('Menu')
                    ->options(['billing.' => 'Pembayaran langganan', 'security.' => 'Keamanan', 'onboarding.' => 'Pendaftaran', 'bank' => 'Bank'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'bank' => $query->where('subject_type', Bank::class),
                        // Path JSON (bukan LIKE teks): format CAST JSON berbeda antara MySQL & SQLite
                        default => $query->where('log_name', PlatformAudit::LOG_NAME)->where('properties->key', 'like', $data['value'].'%'),
                    }),
                SelectFilter::make('causer_id')->label('Oleh')
                    ->options(fn (): array => Admin::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordAction('detail')
            ->recordActions([
                Action::make('detail')->label('Detail')->icon(Heroicon::OutlinedEye)->iconButton()->color('gray')->tooltip('Detail perubahan')
                    ->modalHeading(fn (ActivityLog $record): string => self::eventLabel($record).' · '.self::group($record))
                    ->modalDescription(fn (ActivityLog $record): string => self::subjectName($record))
                    ->modalWidth('3xl')->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
                    ->modalContent(fn (ActivityLog $record) => view('filament.dashboard.activity.detail', [
                        'log' => $record,
                        'changes' => self::changes($record),
                        'causer' => $record->causer instanceof Admin ? $record->causer->name : 'Sistem',
                        'role' => $record->causer instanceof Admin ? 'Super admin' : 'Otomatis',
                        'subject' => self::group($record),
                        'name' => self::subjectName($record),
                        'event' => self::eventLabel($record),
                        'eventColor' => 'info',
                        'tz' => FilamentTimezone::get(),
                    ])),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedClipboardDocumentList, 'perubahan',
            'Perubahan setelan Pembayaran langganan, Keamanan, Pendaftaran, dan Bank tercatat otomatis di sini');
    }

    /**
     * Baris kolom | sebelum | sesudah: setelan = satu baris; bank = kolom yang berubah.
     *
     * @return list<array{field: string, label: string, old: string, new: string}>
     */
    public static function changes(ActivityLog $record): array
    {
        $properties = $record->properties;
        $old = (array) ($properties['old'] ?? []);
        $new = (array) ($properties['attributes'] ?? []);
        $rows = [];

        if ($record->log_name === PlatformAudit::LOG_NAME) {
            $key = (string) ($properties['key'] ?? array_key_first($new) ?? array_key_first($old) ?? '');

            return [[
                'field' => $key,
                'label' => PlatformAudit::label($key),
                'old' => PlatformAudit::format($key, $old[$key] ?? null),
                'new' => PlatformAudit::format($key, $new[$key] ?? null),
            ]];
        }

        $labels = ['code' => 'Kode', 'name' => 'Nama bank', 'short_name' => 'Singkatan', 'color' => 'Warna merek', 'text_color' => 'Warna teks',
            'logo_path' => 'Logo', 'is_active' => 'Aktif', 'sort_order' => 'Urutan'];
        foreach ($record->event === 'deleted' ? $old : $new as $field => $value) {
            $field = (string) $field;
            $fmt = fn (mixed $v): string => match (true) {
                $v === null || $v === '' => '—',
                is_bool($v) => $v ? 'Ya' : 'Tidak',
                $field === 'logo_path' => 'Diunggah',
                default => (string) $v,
            };
            $rows[] = [
                'field' => $field,
                'label' => $labels[$field] ?? $field,
                'old' => $record->event === 'created' ? '—' : $fmt($record->event === 'deleted' ? $value : ($old[$field] ?? null)),
                'new' => $record->event === 'deleted' ? '—' : $fmt($value),
            ];
        }

        return $rows;
    }

    /**
     * @param  'label'|'old'|'new'  $key
     * @return list<string>
     */
    private static function column(ActivityLog $record, string $key): array
    {
        $changes = self::changes($record);
        $values = array_map(fn (array $c): string => $c[$key], array_slice($changes, 0, 3));
        if (count($changes) > 3) {
            $values[] = $key === 'label' ? '+'.(count($changes) - 3).' kolom lain' : "\u{00A0}";
        }

        return $values === [] ? ['—'] : $values;
    }

    private static function eventLabel(ActivityLog $record): string
    {
        return match ($record->event) {
            'created' => $record->log_name === PlatformAudit::LOG_NAME ? 'Diisi' : 'Dibuat',
            'deleted' => 'Dihapus',
            default => 'Diubah',
        };
    }

    private static function group(ActivityLog $record): string
    {
        return $record->log_name === PlatformAudit::LOG_NAME
            ? (string) ($record->properties['group'] ?? 'Sistem')
            : 'Bank';
    }

    private static function subjectName(ActivityLog $record): string
    {
        if ($record->log_name === PlatformAudit::LOG_NAME) {
            return PlatformAudit::label((string) ($record->properties['key'] ?? ''));
        }

        $name = $record->properties['attributes']['name'] ?? $record->properties['old']['name'] ?? null;

        return is_string($name) ? $name : (string) (Bank::query()->whereKey($record->subject_id)->value('name') ?? 'Bank');
    }
}
