<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\RelationManagers;

use App\Enums\ShiftStatus;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\User;
use App\Services\Admin\PlatformActivity;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Detail tenant /admin (Q116): shift yang masih terbuka, merah bila lebih dari 24 jam (mis. tertinggal saat cookie
 * kasir web hilang). Hanya baca: admin tidak menutup shift tenant — owner/supervisor menutupnya dari dashboard.
 */
final class OpenShiftsRelationManager extends RelationManager
{
    protected static string $relationship = 'shifts';

    protected static ?string $title = 'Shift terbuka';

    // Panel /admin hanya untuk guard admin; policy shift ditulis untuk user tenant
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
            ->defaultSort('opened_at')
            ->emptyStateHeading('Tidak ada shift terbuka')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                TextColumn::make('outlet_name')->label('Outlet')->placeholder('—'),
                TextColumn::make('device_name')->label('Perangkat')->placeholder('—'),
                TextColumn::make('opener_name')->label('Dibuka oleh')->placeholder('—'),
                TextColumn::make('opened_at')->label('Sejak')->since()->dateTimeTooltip('j M Y, H.i')->sortable()
                    ->color(fn (Shift $record): ?string => self::isStale($record) ? 'danger' : null)
                    ->icon(fn (Shift $record): ?string => self::isStale($record) ? 'heroicon-m-exclamation-triangle' : null)
                    ->description(fn (Shift $record): ?string => self::isStale($record) ? 'Lebih dari '.PlatformActivity::STALE_SHIFT_HOURS.' jam' : null),
            ]);
    }

    private static function isStale(Shift $shift): bool
    {
        return $shift->opened_at->lte(now()->subHours(PlatformActivity::STALE_SHIFT_HOURS));
    }

    /**
     * Relasi sudah dibatasi tenant pemilik; nama diambil lewat subquery (scope tenant tidak berlaku di /admin).
     *
     * @param  Builder<Shift>  $query
     * @return Builder<Shift>
     */
    private static function query(Builder $query): Builder
    {
        return $query->allTenants()->where('status', ShiftStatus::Open)->addSelect([
            'outlet_name' => Outlet::query()->withoutGlobalScopes()->select('name')->whereColumn('outlets.id', 'shifts.outlet_id'),
            'device_name' => Device::query()->withoutGlobalScopes()->select('name')->whereColumn('devices.id', 'shifts.device_id'),
            'opener_name' => User::query()->withoutGlobalScopes()->select('name')->whereColumn('users.id', 'shifts.opened_by'),
        ]);
    }
}
