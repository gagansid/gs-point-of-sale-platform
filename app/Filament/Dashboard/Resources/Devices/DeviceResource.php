<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Devices;

use App\Actions\Device\RenameDevice;
use App\Actions\Device\SetDeviceRevoked;
use App\Enums\AppPlatform;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Resources\Devices\Pages\ManageDevices;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Pengaturan → Perangkat (device.manage, owner): tablet kasir & browser kasir web (Q54).
 * Ganti nama, cabut (token & sesi langsung tidak berlaku), aktifkan lagi. Perangkat baru terdaftar
 * dari aplikasi (POST /devices) atau otomatis saat kasir web memilih outlet.
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
        return $schema->components([
            TextInput::make('name')->label('Nama')->required()->maxLength(100)->autofocus()
                ->helperText('Mis. "Kasir depan" atau "Laptop bar"'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('last_seen_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('outlet:id,name'))
            ->columns([
                TextColumn::make('name')->label('Nama')->weight('medium')->searchable()->sortable(),
                TextColumn::make('platform')->label('Jenis')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('outlet.name')->label('Outlet')->visible(fn (): bool => Outlet::query()->count() > 1),
                TextColumn::make('open_shift')->label('Shift')
                    ->state(fn (Device $record): string => ($shift = self::openShift($record)) !== null
                        ? 'Terbuka · '.($shift->openedBy->name ?? '–')
                        : '—')
                    ->color(fn (string $state): ?string => $state === '—' ? null : 'warning'),
                TextColumn::make('last_seen_at')->label('Terakhir aktif')->since()->dateTimeTooltip('j M Y, H.i')
                    ->placeholder('Belum pernah')->sortable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (Device $record): string => $record->isRevoked() ? 'Dicabut' : 'Aktif')
                    ->color(fn (string $state): string => $state === 'Aktif' ? 'success' : 'danger'),
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
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ganti nama')->modalWidth('md')
                    ->using(fn (Device $record, array $data): Device => app(RenameDevice::class)->handle($record, (string) $data['name'])),
                ActionGroup::make([
                    Action::make('revoke')->label('Cabut')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                        ->visible(fn (Device $record): bool => ! $record->isRevoked())
                        ->requiresConfirmation()
                        ->modalHeading(fn (Device $record): string => "Cabut {$record->name}?")
                        ->modalDescription('Perangkat langsung keluar dan tidak bisa dipakai bertransaksi sampai diaktifkan lagi (tablet: daftar ulang oleh owner).')
                        ->action(fn (Device $record) => self::setRevoked($record, true)),
                    Action::make('restore')->label('Aktifkan lagi')->icon(Heroicon::OutlinedCheckCircle)
                        ->visible(fn (Device $record): bool => $record->isRevoked())
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

    private static function openShift(Device $device): ?Shift
    {
        return Shift::query()->with('openedBy:id,name')->where('open_device_key', $device->id)->first();
    }

    public static function getPages(): array
    {
        return ['index' => ManageDevices::route('/')];
    }
}
