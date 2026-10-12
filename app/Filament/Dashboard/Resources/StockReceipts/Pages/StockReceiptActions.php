<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockReceipts\Pages;

use App\Actions\Stock\CancelStockReceipt;
use App\Actions\Stock\DeleteStockReceipt;
use App\Actions\Stock\PostStockReceipt;
use App\Enums\StockDocumentStatus;
use App\Filament\Dashboard\Resources\StockReceipts\StockReceiptResource;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Layout;
use App\Models\StockReceipt;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/** Aksi dokumen stok masuk (ADR 0015): posting, batalkan, hapus draf. */
final class StockReceiptActions
{
    public static function post(): Action
    {
        return Action::make('post')->label('Posting')->icon(Heroicon::OutlinedCheckCircle)->color('primary')
            ->visible(fn (StockReceipt $record): bool => $record->isDraft() && StockReceiptResource::canViewAny())
            ->requiresConfirmation()
            ->modalHeading('Posting stok masuk')
            ->modalDescription(fn (StockReceipt $record): HtmlString => Layout::confirmText(
                'Posting <strong>'.e($record->number).'</strong> senilai '.e(Money::format($record->total_cost)).'?',
                'Stok outlet bertambah dan harga modal produk dihitung ulang (rata-rata tertimbang). Setelah diposting dokumen tidak bisa diubah; koreksi lewat Batalkan.',
            ))
            ->modalSubmitActionLabel('Posting')
            ->action(function (StockReceipt $record, Action $action): void {
                RunBusinessAction::run(fn () => app(PostStockReceipt::class)->handle($record, self::user()), $action);
                Notification::make()->success()->title('Stok masuk diposting')->send();
                $action->redirect(StockReceiptResource::getUrl('view', ['record' => $record]));
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')->label('Batalkan')->icon(Heroicon::OutlinedXCircle)->color('danger')
            ->visible(fn (StockReceipt $record): bool => $record->status === StockDocumentStatus::Posted && StockReceiptResource::canViewAny())
            ->modalHeading('Batalkan stok masuk')
            ->modalDescription('Stok outlet dikurangi kembali sebesar qty dokumen ini (boleh minus). Harga modal tidak dikembalikan; koreksi di form produk bila perlu.')
            ->schema([TextInput::make('reason')->label('Alasan')->required()->maxLength(255)->placeholder('mis. salah input qty')])
            ->modalSubmitActionLabel('Batalkan stok masuk')
            ->action(function (StockReceipt $record, array $data, Action $action): void {
                RunBusinessAction::run(fn () => app(CancelStockReceipt::class)->handle($record, self::user(), (string) $data['reason']), $action);
                Notification::make()->success()->title('Stok masuk dibatalkan')->send();
                $action->redirect(StockReceiptResource::getUrl('view', ['record' => $record]));
            });
    }

    public static function delete(): Action
    {
        return Action::make('delete')->label('Hapus draf')->icon(Heroicon::OutlinedTrash)->color('gray')
            ->visible(fn (StockReceipt $record): bool => $record->isDraft() && StockReceiptResource::canViewAny())
            ->requiresConfirmation()
            ->modalHeading('Hapus draf stok masuk')
            ->modalSubmitActionLabel('Hapus')
            ->action(function (StockReceipt $record, Action $action): void {
                RunBusinessAction::run(fn () => app(DeleteStockReceipt::class)->handle($record), $action);
                Notification::make()->success()->title('Draf dihapus')->send();
                $action->redirect(StockReceiptResource::getUrl('index'));
            });
    }

    private static function user(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }
}
