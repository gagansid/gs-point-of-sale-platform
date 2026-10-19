<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\RelationManagers;

use App\Enums\ShiftStatus;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Shift;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Detail tenant /admin (Q116): perangkat kasir bisnis untuk support — hanya baca, tanpa aksi.
 */
final class DevicesRelationManager extends RelationManager
{
    protected static string $relationship = 'devices';

    protected static ?string $title = 'Perangkat';

    // Panel /admin hanya untuk guard admin; policy perangkat/shift ditulis untuk user tenant
    protected static bool $shouldSkipAuthorization = true;

    /** Hanya di halaman detail (bukan form Ubah). */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === ViewTenant::class;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => self::query($query))
            ->defaultSort('last_seen_at', 'desc')
            ->emptyStateHeading('Belum ada perangkat')
            ->emptyStateIcon('heroicon-o-device-tablet')
            ->columns([
                TextColumn::make('name')->label('Nama')->weight('medium')->searchable(),
                TextColumn::make('outlet_name')->label('Outlet')->placeholder('—'),
                TextColumn::make('platform')->label('Jenis')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('app_version')->label('Versi aplikasi')->fontFamily('mono')->placeholder('—'),
                TextColumn::make('last_seen_at')->label('Terakhir aktif')->since()->dateTimeTooltip('j M Y, H.i')
                    ->placeholder('Belum pernah')->sortable(),
                TextColumn::make('revoked_at')->label('Status')->badge()
                    ->state(fn (Device $record): string => $record->isRevoked() ? 'Dicabut' : 'Aktif')
                    ->color(fn (Device $record): string => $record->isRevoked() ? 'danger' : 'success')
                    ->tooltip(fn (Device $record): ?string => $record->revoked_at?->translatedFormat('j M Y, H.i')),
                TextColumn::make('open_shift')->label('Shift terbuka')->badge()
                    ->state(fn (Device $record): string => $record->getAttribute('open_shift') !== null ? 'Ada' : 'Tidak')
                    ->color(fn (string $state): string => $state === 'Ada' ? 'warning' : 'gray'),
            ]);
    }

    /**
     * Relasi sudah dibatasi tenant pemilik; allTenants melepas scope tenant/outlet (tanpa konteks di /admin).
     *
     * @param  Builder<Device>  $query
     * @return Builder<Device>
     */
    private static function query(Builder $query): Builder
    {
        return $query->allTenants()->addSelect([
            'outlet_name' => Outlet::query()->withoutGlobalScopes()->select('name')->whereColumn('outlets.id', 'devices.outlet_id'),
            'open_shift' => Shift::allTenants()->selectRaw('1')->whereColumn('shifts.device_id', 'devices.id')
                ->where('status', ShiftStatus::Open)->limit(1),
        ]);
    }
}
