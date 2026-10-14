<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Promotions\Pages;

use App\Actions\Promotion\SavePromotion;
use App\Filament\Dashboard\Resources\Promotions\PromotionResource;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Models\Promotion;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

final class ManagePromotions extends ManageRecords
{
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = PromotionResource::class;

    /**
     * Tanpa #[Url(as: 'filters')]: filter disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    /** @return array<Action> */
    protected function getTableCardActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => PromotionResource::canCreate())
                ->modalHeading('Tambah promo')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->successNotificationTitle('Promo dibuat')
                ->using(fn (array $data, CreateAction $action): Promotion => RunBusinessAction::run(
                    fn (): Promotion => app(SavePromotion::class)->handle(null, PromotionResource::payload($data)),
                    $action,
                )),
        ];
    }
}
