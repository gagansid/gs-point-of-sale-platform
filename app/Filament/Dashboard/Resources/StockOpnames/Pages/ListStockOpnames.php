<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockOpnames\Pages;

use App\Actions\Stock\CreateStockOpname;
use App\Enums\StockDocumentStatus;
use App\Filament\Dashboard\Resources\StockOpnames\StockOpnameResource;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Filament\Shared\Tables\StatusTabs;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\StockOpname;
use App\Support\CurrentOutlet;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

final class ListStockOpnames extends ListRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = StockOpnameResource::class;

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
        return StatusTabs::forEnum(StockOpname::class, StockDocumentStatus::class, [StockDocumentStatus::Draft]);
    }

    /** @return array<Action> */
    protected function getTableCardActions(): array
    {
        return [
            Action::make('create')->label('Tambah')->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => StockOpnameResource::canCreate())
                ->modalHeading('Buat lembar stok opname')
                ->modalDescription('Semua produk lacak stok yang dijual di outlet (atau satu kategori) masuk ke lembar dengan stok sistem saat ini')
                ->modalWidth('lg')
                ->modalSubmitActionLabel('Buat')
                ->schema([
                    Select::make('outlet_id')->label('Outlet')->required()->native(false)
                        ->options(fn (): array => Outlet::query()->active()->whereIn('id', CurrentOutlet::ids())->orderBy('name')->pluck('name', 'id')->all())
                        ->visible(fn (): bool => CurrentOutlet::selectedId() === null && count(CurrentOutlet::ids()) > 1),
                    Select::make('category_id')->label('Kategori')->placeholder('Semua produk lacak stok')->native(false)
                        ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'id')->all()),
                    DatePicker::make('counted_on')->label('Tanggal hitung')->required()->native(false)->displayFormat('j M Y')
                        ->default(fn (): string => CurrentOutlet::today())->maxDate(fn (): string => CurrentOutlet::today()),
                    TextInput::make('notes')->label('Catatan')->maxLength(255),
                ])
                ->action(function (array $data, Action $action): void {
                    abort_unless(StockOpnameResource::canCreate(), 403);
                    $outletId = filled($data['outlet_id'] ?? null) && in_array($data['outlet_id'], CurrentOutlet::ids(), true) ? (string) $data['outlet_id'] : CurrentOutlet::getOrFail()->id;

                    $opname = RunBusinessAction::run(fn () => app(CreateStockOpname::class)->handle(Outlet::query()->findOrFail($outletId), [
                        'id' => (string) Str::uuid7(),
                        'category_id' => $data['category_id'] ?? null,
                        'counted_on' => substr((string) $data['counted_on'], 0, 10),
                        'notes' => $data['notes'] ?? null,
                    ]), $action);

                    $action->redirect(StockOpnameResource::getUrl('edit', ['record' => $opname]));
                }),
        ];
    }
}
