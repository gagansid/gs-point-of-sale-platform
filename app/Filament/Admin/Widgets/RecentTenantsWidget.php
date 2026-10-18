<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Widgets\Concerns\AdminList;
use App\Models\Tenant;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Dasbor /admin: bisnis yang baru mendaftar (sambut, bantu onboarding). */
final class RecentTenantsWidget extends TableWidget
{
    use AdminList;

    protected static bool $isLazy = false;

    protected static ?int $sort = 8;

    public function table(Table $table): Table
    {
        return self::compact($table, 'Pendaftaran terbaru', TenantResource::getUrl('index'), 'Belum ada bisnis', 'heroicon-o-building-storefront')
            ->query(fn () => Tenant::query()->latest()->limit(self::LIMIT))
            ->recordUrl(fn (Tenant $record): string => TenantResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->label('Bisnis')->weight('medium')
                    ->description(fn (Tenant $record): string => $record->business_type->getLabel()),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('created_at')->label('Daftar')->alignEnd()->since()
                    ->tooltip(fn (Tenant $record): string => $record->created_at->translatedFormat('j M Y, H.i')),
            ]);
    }
}
