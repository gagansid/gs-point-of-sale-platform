<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Devices;

use App\Actions\Device\RenameDevice;
use App\Actions\Device\SetDeviceRevoked;
use App\Enums\AppPlatform;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Resources\Devices\Pages\ManageDevices;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Pengaturan → Perangkat (device.manage, owner): tablet kasir & browser kasir web (Q54).
 * Ganti nama, cabut (token & sesi langsung tidak berlaku; juga massal), aktifkan lagi. Perangkat baru terdaftar
 * dari aplikasi (POST /devices) atau otomatis saat kasir web memilih outlet. Kasir web menganggur (Q116) ditandai.
 */
final class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static ?string $slug = 'settings/devices';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDeviceTablet;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Perangkat';

    protected static ?string $modelLabel = 'perangkat';

    protected static ?string $pluralModelLabel = 'perangkat';

    protected static ?int $navigationSort = 4;

    protected static bool $isGloballySearchable = false;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasPermission('device.manage');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('name')->label('Nama')->required()->maxLength(100)->autofocus()->columnSpanFull()
                ->helperText('Mis. "Kasir depan" atau "Laptop bar"'),
            // Aktif = tidak dicabut; dimatikan = cabut (token & sesi langsung tidak berlaku)
            Toggle::make('is_active')->label('Aktif')->columnSpanFull()
                ->helperText(fn (?Device $record): string => $record?->platform === AppPlatform::Web
                    ? 'Nonaktif = browser ini keluar dari kasir web. Untuk menghentikan karyawan, matikan "Akses kasir web" di menu Karyawan.'
                    : 'Nonaktif = perangkat langsung keluar dan tidak bisa bertransaksi. Ditolak bila shift masih terbuka.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('last_seen_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['outlet:id,name', 'openShift.openedBy:id,name']))
            ->columns([
                TextColumn::make('name')->label('Nama')->weight('medium')->searchable()->sortable(),
                TextColumn::make('platform')->label('Jenis')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('outlet.name')->label('Outlet')->visible(fn (): bool => Outlet::query()->count() > 1),
                TextColumn::make('open_shift')->label('Shift')
                    ->state(fn (Device $record): string => ($shift = $record->openShift) !== null
                        ? 'Terbuka · '.($shift->openedBy->name ?? '–')
                        : '—')
                    ->color(fn (string $state): ?string => $state === '—' ? null : 'warning'),
                TextColumn::make('last_seen_at')->label('Terakhir aktif')->since()->dateTimeTooltip('j M Y, H.i')
                    ->placeholder('Belum pernah')->sortable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (Device $record): string => match (true) {
                        $record->isRevoked() => 'Dicabut',
                        $record->isIdleWeb($record->openShift !== null) => 'Menganggur',
                        default => 'Aktif',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Aktif' => 'success',
                        'Menganggur' => 'gray',
                        default => 'danger',
                    })
                    ->tooltip(fn (string $state): ?string => $state === 'Menganggur'
                        ? 'Kasir web tanpa shift terbuka yang tidak dipakai lebih dari '.self::idleDays().' hari. Dicabut otomatis malam ini atau dipakai ulang saat kuota perangkat penuh.'
                        : null),
                TextColumn::make('app_version')->label('Versi aplikasi')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('platform')->label('Jenis')->options(AppPlatform::class),
                TernaryFilter::make('revoked')->label('Status')
                    ->trueLabel('Dicabut')->falseLabel('Aktif')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('revoked_at'),
                        false: fn (Builder $q) => $q->whereNull('revoked_at'),
                    ),
                Filter::make('idle')->label('Menganggur')->toggle()
                    ->query(fn (Builder $query) => $query->whereIn('devices.id', Device::query()->idleWeb(Device::idleCutoff())->select('devices.id'))),
            ])
            ->toolbarActions([
                BulkAction::make('bulkRevoke')->label('Cabut')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->visible(fn (): bool => self::canViewAny())
                    ->requiresConfirmation()
                    ->modalHeading('Cabut perangkat')
                    ->modalDescription(fn (Collection $records): string => "Cabut {$records->count()} perangkat terpilih? Perangkat langsung keluar dan tidak bisa dipakai bertransaksi. Perangkat dengan shift terbuka dilewati.")
                    ->modalSubmitActionLabel('Cabut')
                    ->action(fn (Collection $records) => self::bulkRevoke($records))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah')->modalWidth('lg')
                    ->mutateRecordDataUsing(fn (array $data, Device $record): array => [...$data, 'is_active' => ! $record->isRevoked()])
                    ->using(fn (Device $record, array $data, EditAction $action): Device => RunBusinessAction::run(function () use ($record, $data): Device {
                        $device = app(RenameDevice::class)->handle($record, (string) $data['name']);
                        $active = (bool) ($data['is_active'] ?? true);

                        return $active === $device->isRevoked() ? app(SetDeviceRevoked::class)->handle($device, ! $active) : $device;
                    }, $action)),
                ActionGroup::make([
                    Action::make('revoke')->label('Cabut')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                        ->visible(fn (Device $record): bool => ! $record->isRevoked())
                        ->requiresConfirmation()
                        ->modalHeading(fn (Device $record): string => "Cabut {$record->name}?")
                        ->modalDescription(fn (Device $record): string => $record->platform === AppPlatform::Web
                            ? 'Browser ini langsung keluar dari kasir web. Karyawan tetap bisa masuk dari browser lain: untuk menghentikan karyawan, matikan "Akses kasir web" di menu Karyawan.'
                            : 'Perangkat langsung keluar dan tidak bisa dipakai bertransaksi sampai diaktifkan lagi (tablet: daftar ulang oleh owner).')
                        ->action(fn (Device $record) => self::setRevoked($record, true)),
                    Action::make('restore')->label('Aktifkan lagi')->icon(Heroicon::OutlinedCheckCircle)
                        ->visible(fn (Device $record): bool => $record->isRevoked() && ! $record->isReleasedWeb())
                        ->action(fn (Device $record) => self::setRevoked($record, false)),
                ])->tooltip('Aksi lain'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedDeviceTablet, 'perangkat', 'Perangkat terdaftar dari aplikasi kasir atau saat kasir web dipakai');
    }

    private static function setRevoked(Device $device, bool $revoked): void
    {
        abort_unless(self::canViewAny(), 403);

        try {
            app(SetDeviceRevoked::class)->handle($device, $revoked);
            Notification::make()->success()->title($revoked ? 'Perangkat dicabut' : 'Perangkat aktif lagi')->send();
        } catch (BusinessException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    /**
     * Cabut massal lewat SetDeviceRevoked per perangkat; yang ditolak (shift terbuka) dilewati dan dilaporkan. Rekaman
     * terpilih dimuat ulang lewat query Device (scope tenant & outlet berlaku lagi).
     *
     * @param  Collection<int, Model>  $records
     */
    private static function bulkRevoke(Collection $records): void
    {
        abort_unless(self::canViewAny(), 403);

        $revoked = 0;
        $skipped = 0;
        $action = app(SetDeviceRevoked::class);

        foreach (Device::query()->whereKey($records->modelKeys())->whereNull('revoked_at')->get() as $device) {
            try {
                $action->handle($device, true);
                $revoked++;
            } catch (BusinessException) {
                $skipped++;
            }
        }

        Notification::make()->success()->title("{$revoked} perangkat dicabut")
            ->body($skipped > 0 ? "{$skipped} perangkat dilewati karena shift masih terbuka." : null)
            ->send();
    }

    private static function idleDays(): int
    {
        return max(1, (int) config('pos.web_cashier.idle_days'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageDevices::route('/')];
    }
}
