<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Products\Pages;

use App\Actions\Product\ImportProducts;
use App\Enums\ProductKind;
use App\Exceptions\BusinessException;
use App\Exports\ProductImportTemplate;
use App\Filament\Dashboard\Resources\Products\ProductResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Imports\ProductImportSheet;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Support\CurrentOutlet;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

final class ListProducts extends ListRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = ProductResource::class;

    /**
     * Dideklarasikan ulang tanpa #[Url(as: 'filters')] dari ListRecords agar URL tetap rapi
     * (bukan ?filters[category_id][value]=...). Filter tetap bertahan lewat persistFiltersInSession().
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    /** Tanpa #[Url(as: 'tab')]: tab aktif disimpan di session (HasCardTabs). */
    public ?string $activeTab = null;

    /**
     * Tab cepat di atas tabel; jumlah per tab mengikuti tenant aktif (global scope).
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Semua')->badge(fn (): int => Product::query()->count()),
            // ADR 0020
            'ingredients' => Tab::make('Bahan baku')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('kind', ProductKind::Ingredient->value))
                ->badge(fn (): int => Product::query()->where('kind', ProductKind::Ingredient->value)->count()),
            'bundles' => Tab::make('Paket')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('kind', ProductKind::Bundle->value))
                ->badge(fn (): int => Product::query()->where('kind', ProductKind::Bundle->value)->count()),
            'favorite' => Tab::make('Favorit')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_favorite', true))
                ->badge(fn (): int => Product::query()->where('is_favorite', true)->count()),
            'active' => Tab::make('Aktif')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', true))
                ->badge(fn (): int => Product::query()->where('is_active', true)->count()),
            'sold_out' => Tab::make('Habis')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->scopes(['soldOut' => [self::outletId()]]))
                ->badge(fn (): int => Product::query()->soldOut(self::outletId())->count())
                ->badgeColor('danger'),
            'low_stock' => Tab::make('Stok menipis')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->scopes(['lowStock' => [self::outletId()]]))
                ->badge(fn (): int => Product::query()->lowStock(self::outletId())->count())
                ->badgeColor('warning'),
        ];
    }

    /** Stok & ketersediaan mengikuti outlet aktif di pemilih outlet sidebar (ADR 0010). */
    private static function outletId(): string
    {
        return CurrentOutlet::getOrFail()->id;
    }

    public function getSubheading(): ?string
    {
        // Hanya relevan bila bisnis punya lebih dari satu outlet
        return Outlet::query()->count() > 1 ? 'Stok & ketersediaan: '.CurrentOutlet::getOrFail()->name : null;
    }

    /**
     * @return array<Action>
     */
    protected function getTableCardActions(): array
    {
        return [
            $this->importAction(),
            CreateAction::make()->label('Tambah')->icon(Heroicon::OutlinedPlus),
        ];
    }

    /** Import produk dari Excel/CSV (Q59); semua baris valid atau tidak ada yang disimpan. */
    public function importAction(): Action
    {
        return Action::make('import')->label('Import')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
            ->visible(fn (): bool => ProductResource::canCreate())
            ->modalHeading('Import produk dari Excel')
            ->modalDescription('Unduh template, isi satu produk per baris, lalu unggah. SKU yang sudah ada akan diperbarui; kategori baru dibuat otomatis. Stok awal untuk outlet '.CurrentOutlet::getOrFail()->name.'.')
            ->modalSubmitActionLabel('Import')
            ->modalWidth('lg')
            ->schema([
                FileUpload::make('file')->label('File Excel atau CSV')
                    ->disk('local')->directory('imports')->visibility('private')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel', 'text/csv', 'text/plain',
                    ])
                    ->maxSize(2048)
                    ->required()
                    ->helperText('.xlsx atau .csv · maks. 2 MB · maks. '.ImportProducts::MAX_ROWS.' baris'),
            ])
            ->extraModalFooterActions([
                Action::make('template')->label('Unduh template')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->action(fn () => Excel::download(new ProductImportTemplate, 'template-import-produk.xlsx')),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless(ProductResource::canCreate(), 403);
                $path = (string) $data['file'];

                try {
                    $rows = Excel::toArray(new ProductImportSheet, $path, 'local')[0] ?? [];
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);
                    $result = app(ImportProducts::class)->handle($rows, CurrentOutlet::getOrFail(), $user);

                    Notification::make()->success()->title('Import selesai')
                        ->body("{$result['created']} produk baru, {$result['updated']} produk diperbarui")->send();
                } catch (BusinessException $e) {
                    /** @var array<string, list<string>> $details */
                    $details = is_array($e->details) ? $e->details : [];
                    $lines = collect($details)->take(8)
                        ->map(fn (mixed $messages, string $line): string => ucfirst($line).': '.implode('; ', (array) $messages))
                        ->implode("\n");

                    Notification::make()->danger()->title($e->getMessage())->body($lines !== '' ? $lines : null)->persistent()->send();
                    $action->halt();
                } finally {
                    Storage::disk('local')->delete($path);
                }
            });
    }
}
