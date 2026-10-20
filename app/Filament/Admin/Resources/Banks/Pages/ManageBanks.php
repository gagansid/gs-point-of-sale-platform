<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Banks\Pages;

use App\Filament\Admin\Resources\Banks\BankResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Filament\Shared\Tables\StatusTabs;
use App\Models\Bank;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;

final class ManageBanks extends ManageRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = BankResource::class;

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
        return StatusTabs::make(Bank::class);
    }

    /** @return array<Action> */
    protected function getTableCardActions(): array
    {
        return [
            CreateAction::make()->label('Tambah')->icon(Heroicon::OutlinedPlus)->modalHeading('Tambah bank')->modalWidth('2xl')
                ->createAnother(false)->successNotificationTitle('Bank ditambahkan')
                ->using(fn (array $data, CreateAction $action): Bank => BankResource::save(null, $data, $action)),
        ];
    }
}
