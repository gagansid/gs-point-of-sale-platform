<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Widgets\Concerns\AdminList;
use App\Services\Admin\PlatformActivity;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Dasbor /admin (Q116): shift terbuka lebih dari 24 jam lintas bisnis — kemungkinan tertinggal (mis. cookie kasir web
 * hilang). Support menghubungi bisnisnya; admin tidak menutup shift tenant. Di-cache lewat DashboardCache.
 */
final class StaleShiftsWidget extends TableWidget
{
    use AdminList;

    protected static bool $isLazy = false;

    protected static ?int $sort = 15;

    public function table(Table $table): Table
    {
        $stale = app(PlatformActivity::class)->staleShifts(PlatformActivity::STALE_SHIFT_HOURS, self::LIMIT);

        return self::compact($table, 'Shift terbuka > '.PlatformActivity::STALE_SHIFT_HOURS.' jam ('.$stale['count'].')', null, 'Tidak ada shift tertinggal', 'heroicon-o-check-circle', 'heroicon-o-lock-open')
            ->emptyStateDescription('Shift yang terbuka lebih dari '.PlatformActivity::STALE_SHIFT_HOURS.' jam akan muncul di sini')
            ->records(fn (): array => collect($stale['rows'])->keyBy('shift_id')->all())
            ->recordUrl(fn (array $record): string => TenantResource::getUrl('view', ['record' => $record['tenant_id']]))
            ->columns([
                TextColumn::make('tenant_name')->label('Bisnis')->weight('medium')
                    ->description(fn (array $record): string => ($record['outlet_name'] ?? '—').' · '.($record['device_name'] ?? '—')),
                TextColumn::make('opened_at')->label('Dibuka')->alignEnd()->color('danger')
                    ->state(fn (array $record): string => CarbonImmutable::parse($record['opened_at'])->locale('id')->diffForHumans()),
            ]);
    }
}
