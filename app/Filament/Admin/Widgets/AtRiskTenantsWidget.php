<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\TenantStatus;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Widgets\Concerns\AdminList;
use App\Services\Admin\PlatformActivity;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Dasbor /admin (D3): bisnis aktif yang tidak bertransaksi 14 hari — belum mulai memakai kasir (bantu onboarding) atau
 * berhenti memakai (risiko tidak memperpanjang).
 */
final class AtRiskTenantsWidget extends TableWidget
{
    use AdminList;

    protected static bool $isLazy = false;

    protected static ?int $sort = 13;

    public function table(Table $table): Table
    {
        $risk = app(PlatformActivity::class)->atRiskTenants(14, self::LIMIT);

        return self::compact($table, 'Tidak bertransaksi 14 hari ('.$risk['count'].')', TenantResource::getUrl('index'), 'Semua bisnis aktif bertransaksi', 'heroicon-o-check-circle', 'heroicon-o-exclamation-triangle')
            ->emptyStateDescription('Bisnis aktif berumur ≥ 14 hari tanpa transaksi akan muncul di sini')
            ->records(fn (): array => collect($risk['rows'])->keyBy('tenant_id')->all())
            ->recordUrl(fn (array $record): string => TenantResource::getUrl('view', ['record' => $record['tenant_id']]))
            ->columns([
                TextColumn::make('name')->label('Bisnis')->weight('medium')
                    ->description(fn (array $record): string => ($record['plan'] ?? 'Tanpa paket').' · '.TenantStatus::from($record['status'])->getLabel()),
                TextColumn::make('last_order_at')->label('Transaksi terakhir')->alignEnd()
                    ->state(fn (array $record): string => $record['last_order_at'] === null
                        ? 'Belum pernah'
                        : CarbonImmutable::parse($record['last_order_at'])->locale('id')->diffForHumans())
                    ->color(fn (array $record): string => $record['last_order_at'] === null ? 'warning' : 'danger'),
            ]);
    }
}
