<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Enums\TenantStatus;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

final class ListTenants extends ListRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = TenantResource::class;

    /**
     * Tanpa #[Url(as: 'filters')]: filter disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    /** Tanpa #[Url(as: 'tab')]: tab aktif disimpan di session (HasCardTabs). */
    public ?string $activeTab = null;

    /**
     * Panel /admin: hitungan lintas tenant memang diizinkan di sini (CLAUDE.md).
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Semua')->badge(fn (): int => Tenant::query()->count()),
            'active' => self::statusTab('Aktif', TenantStatus::Active),
            'trial' => self::statusTab('Trial', TenantStatus::Trial),
            'suspended' => self::statusTab('Suspended', TenantStatus::Suspended)->badgeColor('danger'),
            // Langganan berakhir dalam 7 hari (atau sudah lewat) dan belum disuspend
            'expiring' => Tab::make('Segera berakhir')
                ->modifyQueryUsing(fn (Builder $query): Builder => self::expiring($query))
                ->badge(fn (): int => self::expiring(Tenant::query())->count())
                ->badgeColor('warning'),
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getTableCardActions(): array
    {
        return [CreateAction::make()->label('Tambah')->icon(Heroicon::OutlinedPlus)];
    }

    private static function statusTab(string $label, TenantStatus $status): Tab
    {
        return Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $status))
            ->badge(fn (): int => Tenant::query()->where('status', $status)->count());
    }

    /**
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    private static function expiring(Builder $query): Builder
    {
        return $query->where('status', '!=', TenantStatus::Suspended)
            ->whereNotNull('subscription_ends_at')
            ->where('subscription_ends_at', '<=', now()->addDays(7));
    }
}
