<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Outlets\Pages;

use App\Actions\Outlet\CreateOutlet;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Resources\Outlets\OutletResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Filament\Shared\Tables\StatusTabs;
use App\Models\Outlet;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;

final class ManageOutlets extends ManageRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = OutletResource::class;

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
        return StatusTabs::make(Outlet::class);
    }

    public function getSubheading(): ?string
    {
        $user = auth()->user();
        $limit = $user instanceof User ? $user->tenant->outletLimit() : null;

        return $limit !== null
            ? 'Paket Anda: maksimal '.$limit.' outlet aktif ('.Outlet::query()->active()->count().' terpakai)'
            : null;
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
                ->modalHeading('Tambah outlet')
                ->modalDescription('Pajak, service, pembulatan & batas diskon disalin dari outlet pertama; bisa diubah di Profil outlet')
                ->modalWidth('xl')
                ->createAnother(false)
                ->successNotificationTitle('Outlet berhasil ditambahkan')
                ->using(function (array $data, CreateAction $action): Outlet {
                    try {
                        return app(CreateOutlet::class)->handle(
                            (string) $data['code'],
                            (string) $data['name'],
                            isset($data['address']) ? (string) $data['address'] : null,
                            (string) $data['timezone'],
                        );
                    } catch (BusinessException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();

                        throw $e;
                    }
                }),
        ];
    }
}
