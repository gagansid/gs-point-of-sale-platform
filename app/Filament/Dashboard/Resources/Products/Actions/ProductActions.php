<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Products\Actions;

use App\Actions\Product\AdjustStock;
use App\Actions\Product\DeleteProduct;
use App\Actions\Product\SetProductAvailability;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
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

    public static function toggleAvailability(): Action
    {
        return Action::make('toggleAvailability')
            ->label(fn (Product $record): string => $record->is_available ? 'Tandai habis' : 'Tandai tersedia')
            ->icon(fn (Product $record): Heroicon => $record->is_available ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheckCircle)
            ->visible(fn (): bool => self::user()?->can('product.toggle_available') ?? false)
            ->action(function (Product $record): void {
                app(SetProductAvailability::class)->handle($record, ! $record->is_available);

                Notification::make()->success()
                    ->title($record->is_available ? 'Menu ditandai tersedia' : 'Menu ditandai habis')
                    ->send();
            });
    }

    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->modalHeading(fn (Product $record): string => "Hapus produk {$record->name}?")
            ->modalDescription('Produk hilang dari aplikasi kasir. Riwayat transaksi tetap utuh')
            ->using(function (Product $record): bool {
                app(DeleteProduct::class)->handle($record);

                return true;
            });
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
