<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\BusinessType;
use App\Enums\SalesLeadStatus;
use App\Filament\Admin\Resources\SalesLeads\SalesLeadResource;
use App\Filament\Admin\Widgets\Concerns\AdminList;
use App\Models\SalesLead;
use App\Support\Edition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Dasbor /admin: calon pelanggan dari halaman depan yang belum dihubungi, terlama dulu (jangan dibiarkan). */
final class NewLeadsWidget extends TableWidget
{
    use AdminList;

    protected static bool $isLazy = false;

    protected static ?int $sort = 9;

    public static function canView(): bool
    {
        return Edition::isSaas();
    }

    public function table(Table $table): Table
    {
        return self::compact($table, 'Calon pelanggan belum dihubungi', SalesLeadResource::getUrl('index'), 'Semua calon pelanggan sudah dihubungi', 'heroicon-o-check-circle', 'heroicon-o-chat-bubble-left-right')
            ->query(fn () => SalesLead::query()->where('status', SalesLeadStatus::New)->oldest()->limit(self::LIMIT))
            ->columns([
                TextColumn::make('business_name')->label('Bisnis')->weight('medium')
                    ->description(fn (SalesLead $record): string => trim($record->name.' · '.($record->city ?? ''), ' ·')),
                TextColumn::make('business_type')->label('Jenis')->badge()->color('gray')->visibleFrom('xl')
                    ->formatStateUsing(fn (?string $state): string => BusinessType::tryFrom((string) $state)?->getLabel() ?? (string) $state),
                TextColumn::make('created_at')->label('Masuk')->alignEnd()->since()
                    ->color(fn (SalesLead $record): ?string => $record->created_at->lte(now()->subDays(2)) ? 'danger' : null),
            ]);
    }
}
