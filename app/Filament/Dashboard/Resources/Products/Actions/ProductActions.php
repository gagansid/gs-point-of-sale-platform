<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Products\Actions;

use App\Actions\Product\AdjustStock;
use App\Actions\Product\DeleteProduct;
use App\Actions\Product\SetProductAvailability;
use App\Actions\Product\SetProductFavorite;
use App\Exceptions\BusinessException;
use App\Filament\Shared\Layout;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Aksi baris produk (row-actions.md). Semua memanggil Action bisnis.
 */
final class ProductActions
{
    public static function adjustStock(): Action
    {
        return Action::make('adjustStock')
            ->label('Sesuaikan stok')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->visible(fn (Product $record): bool => $record->track_stock && (self::user()?->can('stock.adjust') ?? false))
            ->modalHeading(fn (Product $record): string => "Sesuaikan stok {$record->name}")
            ->modalDescription(fn (Product $record): string => "Stok saat ini: {$record->stock_qty}")
            ->modalSubmitActionLabel('Simpan')
            ->modalWidth('md')
            ->schema([
                TextInput::make('qty_change')
                    ->label('Perubahan stok')
                    ->helperText('Positif = tambah (mis. 24), negatif = kurang (mis. -3)')
                    ->integer()->required()->notIn([0])->minValue(-1000000)->maxValue(1000000),
                TextInput::make('reason')->label('Alasan')->placeholder('Barang masuk / rusak / stok opname')->required()->maxLength(255),
            ])
            ->action(function (Product $record, array $data, Action $action): void {
                try {
                    // ID baru per submit; tombol yang ditekan dua kali dicegah Filament (loading state)
                    app(AdjustStock::class)->handle($record, self::user() ?? abort(403), (string) Str::uuid7(), (int) $data['qty_change'], (string) $data['reason']);
                } catch (BusinessException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }

                Notification::make()->success()->title('Stok berhasil disesuaikan')->send();
            });
    }

    /**
     * Tandai habis: menu hilang dari kasir, jadi wajib konfirmasi. Hanya tampil untuk produk tersedia.
     */
    public static function markSoldOut(): Action
    {
        return Action::make('markSoldOut')
            ->label('Tandai habis')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->visible(fn (Product $record): bool => $record->is_available && self::canToggleAvailability())
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedNoSymbol)
            ->modalIconColor('warning')
            ->modalHeading('Tandai habis')
            ->modalDescription(fn (Product $record): HtmlString => Layout::confirmText(
                'Tandai <strong>'.e($record->name).'</strong> sebagai habis?',
                'Menu tidak bisa dipesan di aplikasi kasir sampai ditandai tersedia lagi.',
            ))
            ->modalSubmitActionLabel('Tandai habis')
            ->action(function (Product $record): void {
                app(SetProductAvailability::class)->handle($record, false);

                Notification::make()->success()->title('Menu ditandai habis')->send();
            });
    }

    /**
     * Tandai tersedia: mengembalikan menu ke kasir, langsung tanpa modal. Hanya tampil untuk produk habis.
     */
    public static function markAvailable(): Action
    {
        return Action::make('markAvailable')
            ->label('Tandai tersedia')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->visible(fn (Product $record): bool => ! $record->is_available && self::canToggleAvailability())
            ->action(function (Product $record): void {
                app(SetProductAvailability::class)->handle($record, true);

                Notification::make()->success()->title('Menu ditandai tersedia')->send();
            });
    }

    public static function toggleFavorite(): Action
    {
        return Action::make('toggleFavorite')
            ->visible(fn (): bool => self::user()?->can('product.manage') ?? false)
            ->action(function (Product $record): void {
                app(SetProductFavorite::class)->handle($record, ! $record->is_favorite);

                Notification::make()->success()
                    ->title($record->is_favorite ? 'Ditambahkan ke favorit' : 'Dihapus dari favorit')
                    ->send();
            });
    }

    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->modalHeading('Hapus produk')
            ->modalDescription(fn (Product $record): HtmlString => Layout::confirmText(
                'Yakin ingin menghapus <strong>'.e($record->name).'</strong>?',
                'Produk hilang dari aplikasi kasir. Riwayat transaksi tetap utuh.',
            ))
            ->using(function (Product $record): bool {
                app(DeleteProduct::class)->handle($record);

                return true;
            });
    }

    /**
     * Tandai habis/tersedia untuk produk terpilih. Query rekaman terpilih sudah dibatasi tenant (global scope).
     */
    public static function bulkSetAvailability(bool $available): BulkAction
    {
        return BulkAction::make($available ? 'bulkMarkAvailable' : 'bulkMarkSoldOut')
            ->label($available ? 'Tandai tersedia' : 'Tandai habis')
            ->icon($available ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedNoSymbol)
            ->visible(fn (): bool => self::user()?->can('product.toggle_available') ?? false)
            ->requiresConfirmation()
            ->modalIcon($available ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedNoSymbol)
            ->modalIconColor($available ? 'success' : 'warning')
            ->modalHeading($available ? 'Tandai tersedia' : 'Tandai habis')
            ->modalDescription(fn (Collection $records): HtmlString => Layout::confirmText(
                ($available ? 'Tandai tersedia' : 'Tandai habis').' <strong>'.$records->count().' produk</strong> terpilih?',
                $available ? 'Menu bisa dipesan lagi di aplikasi kasir.' : 'Menu tidak bisa dipesan di aplikasi kasir sampai ditandai tersedia lagi.',
            ))
            ->modalSubmitActionLabel($available ? 'Tandai tersedia' : 'Tandai habis')
            ->action(
                function (Collection $records) use ($available): void {
                    $action = app(SetProductAvailability::class);
                    self::selected($records)->each(fn (Product $product) => $action->handle($product, $available));

                    Notification::make()->success()
                        ->title($records->count().' produk ditandai '.($available ? 'tersedia' : 'habis'))
                        ->send();
                },
            )
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Muat ulang rekaman terpilih lewat query Product (global scope tenant ikut berlaku lagi)
     * sehingga aksi massal hanya pernah menyentuh produk tenant sendiri.
     *
     * @param  Collection<int, Model>  $records
     * @return Collection<int, Product>
     */
    private static function selected(Collection $records): Collection
    {
        return Product::query()->whereKey($records->modelKeys())->get();
    }

    private static function canToggleAvailability(): bool
    {
        return self::user()?->can('product.toggle_available') ?? false;
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
