<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Admins\Pages;

use App\Actions\Admin\CreateAdmin;
use App\Filament\Admin\Resources\Admins\AdminResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Models\Admin;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

final class ManageAdmins extends ManageRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = AdminResource::class;

    /**
     * Tanpa #[Url(as: 'filters')]: filter disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    /** Tanpa #[Url(as: 'tab')]: tab aktif disimpan di session (HasCardTabs). */
    public ?string $activeTab = null;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Semua')->badge(fn (): int => Admin::query()->count()),
            'with_2fa' => Tab::make('2FA aktif')
                ->modifyQueryUsing(fn (Builder $q): Builder => $q->whereNotNull('app_authentication_secret'))
                ->badge(fn (): int => Admin::query()->whereNotNull('app_authentication_secret')->count()),
            'without_2fa' => Tab::make('Belum 2FA')
                ->modifyQueryUsing(fn (Builder $q): Builder => $q->whereNull('app_authentication_secret'))
                ->badge(fn (): int => Admin::query()->whereNull('app_authentication_secret')->count())
                ->badgeColor('warning'),
        ];
    }

    /** @return array<Action> */
    protected function getTableCardActions(): array
    {
        return [
            CreateAction::make()->label('Tambah')->icon(Heroicon::OutlinedPlus)->modalHeading('Tambah admin')->modalWidth('lg')
                ->schema(AdminResource::formFields(creating: true))
                ->using(fn (array $data): Admin => app(CreateAdmin::class)->handle((string) $data['name'], (string) $data['email'], (string) $data['password'])),
        ];
    }
}
