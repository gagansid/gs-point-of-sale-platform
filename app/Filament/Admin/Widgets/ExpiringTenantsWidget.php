<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\TenantStatus;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Widgets\Concerns\AdminList;
use App\Models\Tenant;
use App\Support\Edition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Dasbor /admin: trial & langganan yang berakhir dalam 14 hari (tindak lanjut: tagih / hubungi). */
final class ExpiringTenantsWidget extends TableWidget
{
    use AdminList;

    protected static bool $isLazy = false;

    protected static ?int $sort = 6;

    public static function canView(): bool
    {
        return Edition::isSaas();
    }

    public function table(Table $table): Table
    {
        return self::compact($table, 'Segera berakhir (14 hari)', TenantResource::getUrl('index', ['tab' => 'expiring']), 'Tidak ada yang segera berakhir', 'heroicon-o-check-circle')
            ->query(fn () => Tenant::query()->with('plan:id,name')
                ->where('status', '!=', TenantStatus::Suspended)
                ->whereBetween('subscription_ends_at', [now(), now()->addDays(14)])
                ->orderBy('subscription_ends_at')->limit(self::LIMIT))
            ->recordUrl(fn (Tenant $record): string => TenantResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->label('Bisnis')->weight('medium')
                    ->description(fn (Tenant $record): string => $record->plan->name ?? 'Tanpa paket'),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('subscription_ends_at')->label('Berakhir')->alignEnd()
                    ->formatStateUsing(fn (Tenant $record): string => self::daysLeft($record))
                    ->description(fn (Tenant $record): string => $record->subscription_ends_at?->translatedFormat('j M Y') ?? '')
                    ->color(fn (Tenant $record): string => $record->subscription_ends_at !== null && $record->subscription_ends_at->lte(now()->addDays(3)) ? 'danger' : 'warning'),
            ]);
    }

    private static function daysLeft(Tenant $tenant): string
    {
        $days = (int) ceil(now()->diffInDays($tenant->subscription_ends_at, false));

        return $days <= 0 ? 'Hari ini' : $days.' hari lagi';
    }
}
