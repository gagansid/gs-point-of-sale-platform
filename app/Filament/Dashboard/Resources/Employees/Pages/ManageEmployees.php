<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Employees\Pages;

use App\Actions\User\Data\EmployeeData;
use App\Actions\User\SaveEmployee;
use App\Filament\Dashboard\Resources\Employees\EmployeeResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Filament\Shared\Tables\StatusTabs;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;

final class ManageEmployees extends ManageRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = EmployeeResource::class;

    /**
     * Tanpa #[Url(as: 'filters')]: filter disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    /** Tanpa #[Url(as: 'tab')]: tab aktif disimpan di session (HasCardTabs). */
    public ?string $activeTab = null;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return StatusTabs::make(User::class);
    }

    /**
     * @return array<Action>
     */
    protected function getTableCardActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Tambah karyawan')
                ->modalWidth('2xl')
                ->createAnother(false)
                ->using(fn (array $data, CreateAction $action): User => EmployeeResource::orNotify(
                    fn (): User => app(SaveEmployee::class)->handle(null, EmployeeData::fromArray([...$data, 'email' => EmployeeResource::emailFor($data)]))['user'],
                    $action,
                )),
        ];
    }
}
