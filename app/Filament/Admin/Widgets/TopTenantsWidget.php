<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Widgets\Concerns\AdminList;
use App\Services\Admin\PlatformActivity;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Dasbor /admin (D3): bisnis dengan transaksi terbanyak 30 hari (pelanggan andalan; kandidat upsell). */
final class TopTenantsWidget extends TableWidget
{
    use AdminList;

    protected static bool $isLazy = false;

    protected static ?int $sort = 12;

    public function table(Table $table): Table
    {
        return self::compact($table, 'Bisnis paling aktif (30 hari)', TenantResource::getUrl('index'), 'Belum ada transaksi', 'heroicon-o-receipt-percent')
            ->records(fn (): array => collect(app(PlatformActivity::class)->topTenants(30, self::LIMIT))->keyBy('tenant_id')->all())
            ->recordUrl(fn (array $record): string => TenantResource::getUrl('view', ['record' => $record['tenant_id']]))
            ->columns([
                TextColumn::make('name')->label('Bisnis')->weight('medium'),
                TextColumn::make('count')->label('Transaksi')->alignEnd()->numeric(locale: 'id'),
                TextColumn::make('gmv')->label('Nilai')->alignEnd()->formatStateUsing(fn (string $state): string => Money::format($state)),
            ]);
    }
}
