<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Vouchers\Pages;

use App\Actions\Member\SaveVoucher;
use App\Enums\VoucherSource;
use App\Filament\Dashboard\Resources\Vouchers\VoucherResource;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Models\Voucher;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

final class ManageVouchers extends ManageRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = VoucherResource::class;

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
        $count = fn (VoucherSource $source): int => Voucher::query()->where('source', $source)->count();

        return [
            'promo' => Tab::make('Kode promo')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('source', VoucherSource::Owner))
                ->badge(fn (): int => $count(VoucherSource::Owner)),
            'birthday' => Tab::make('Ulang tahun')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('source', VoucherSource::Birthday))
                ->badge(fn (): int => $count(VoucherSource::Birthday)),
        ];
    }

    /** @return array<Action> */
    protected function getTableCardActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => VoucherResource::canCreate())
                ->modalHeading('Tambah kode promo')
                ->modalWidth('2xl')
                ->createAnother(false)
                ->successNotificationTitle('Voucher dibuat')
                ->using(fn (array $data, CreateAction $action): Voucher => RunBusinessAction::run(
                    fn (): Voucher => app(SaveVoucher::class)->handle(null, $data),
                    $action,
                )),
        ];
    }
}
