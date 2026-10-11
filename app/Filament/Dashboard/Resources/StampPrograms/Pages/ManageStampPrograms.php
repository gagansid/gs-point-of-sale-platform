<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StampPrograms\Pages;

use App\Actions\Member\SaveStampProgram;
use App\Filament\Dashboard\Resources\StampPrograms\StampProgramResource;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Filament\Shared\Tables\StatusTabs;
use App\Models\StampProgram;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;

final class ManageStampPrograms extends ManageRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = StampProgramResource::class;

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
        return StatusTabs::make(StampProgram::class);
    }

    /** @return array<Action> */
    protected function getTableCardActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => StampProgramResource::canCreate())
                ->modalHeading('Tambah kartu stempel')
                ->modalWidth('2xl')
                ->createAnother(false)
                ->successNotificationTitle('Kartu stempel dibuat')
                ->using(fn (array $data, CreateAction $action): StampProgram => RunBusinessAction::run(
                    fn (): StampProgram => app(SaveStampProgram::class)->handle(null, $data),
                    $action,
                )),
        ];
    }
}
