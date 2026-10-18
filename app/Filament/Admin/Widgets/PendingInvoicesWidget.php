<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\InvoiceStatus;
use App\Filament\Admin\Resources\SubscriptionInvoices\SubscriptionInvoiceResource;
use App\Filament\Admin\Widgets\Concerns\AdminList;
use App\Models\SubscriptionInvoice;
use App\Support\Edition;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Dasbor /admin: tagihan langganan menunggu bayar, jatuh tempo terdekat dulu (transfer manual → Tandai lunas). */
final class PendingInvoicesWidget extends TableWidget
{
    use AdminList;

    protected static bool $isLazy = false;

    protected static ?int $sort = 7;

    public static function canView(): bool
    {
        return Edition::isSaas();
    }

    public function table(Table $table): Table
    {
        return self::compact($table, 'Tagihan menunggu', SubscriptionInvoiceResource::getUrl('index', ['filters' => ['status' => ['value' => InvoiceStatus::Pending->value]]]), 'Tidak ada tagihan menunggu', 'heroicon-o-check-circle')
            // Lintas tenant: panel /admin
            ->query(fn () => SubscriptionInvoice::allTenants()->with(['tenant:id,name', 'items'])
                ->where('status', InvoiceStatus::Pending)->orderBy('due_at')->limit(self::LIMIT))
            ->columns([
                TextColumn::make('number')->label('Tagihan')->weight('medium')
                    ->description(fn (SubscriptionInvoice $record): string => (string) $record->tenant?->name),
                TextColumn::make('kind')->label('Rincian')->wrap()->visibleFrom('xl')
                    ->formatStateUsing(fn (SubscriptionInvoice $record): string => $record->description()),
                TextColumn::make('amount')->label('Total')->alignEnd()->formatStateUsing(fn (string $state): string => Money::format($state))
                    ->description(fn (SubscriptionInvoice $record): string => 'Jatuh tempo '.$record->due_at->translatedFormat('j M')),
            ]);
    }
}
