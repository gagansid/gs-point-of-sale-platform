<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SubscriptionInvoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Admin\Resources\SubscriptionInvoices\SubscriptionInvoiceResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Models\SubscriptionInvoice;
use App\Support\Edition;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filter tetap di URL (bawaan ListRecords): tautan widget "Tagihan menunggu" & "Semua tagihan" di detail bisnis
 * membuka daftar dengan filter terpasang.
 */
final class ListSubscriptionInvoices extends ListRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = SubscriptionInvoiceResource::class;

    /** Tanpa #[Url(as: 'tab')]: tab aktif disimpan di session (HasCardTabs). */
    public ?string $activeTab = null;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $query = fn (): Builder => SubscriptionInvoice::query()->withoutGlobalScopes();
        $tabs = ['all' => Tab::make('Semua')->badge(fn (): int => $query()->count())];

        foreach (InvoiceStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->getLabel())
                ->modifyQueryUsing(fn (Builder $q): Builder => $q->where('status', $status))
                ->badge(fn (): int => $query()->where('status', $status)->count())
                ->badgeColor($status === InvoiceStatus::Pending ? 'warning' : null);
        }

        // Menunggu tetapi sudah lewat jatuh tempo (belum diproses pos:billing-reminders)
        $tabs['overdue'] = Tab::make('Lewat jatuh tempo')
            ->modifyQueryUsing(fn (Builder $q): Builder => $q->where('status', InvoiceStatus::Pending)->where('due_at', '<', now()))
            ->badge(fn (): int => $query()->where('status', InvoiceStatus::Pending)->where('due_at', '<', now())->count())
            ->badgeColor('danger');

        return $tabs;
    }

    /** @return array<Action> */
    protected function getTableCardActions(): array
    {
        return [CreateAction::make()->label('Tambah')->icon(Heroicon::OutlinedPlus)->visible(fn (): bool => Edition::isSaas())];
    }
}
