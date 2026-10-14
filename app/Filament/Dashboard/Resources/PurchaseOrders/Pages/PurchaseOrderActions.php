<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\PurchaseOrders\Pages;

use App\Actions\Stock\CancelPurchaseOrder;
use App\Actions\Stock\DeletePurchaseOrder;
use App\Actions\Stock\ReceivePurchaseOrder;
use App\Actions\Stock\SendPurchaseOrder;
use App\Filament\Dashboard\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Dashboard\Resources\StockReceipts\StockReceiptResource;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Layout;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/** Aksi purchase order (ADR 0021): kirim, terima barang, batalkan, hapus draf. */
final class PurchaseOrderActions
{
    public static function send(): Action
    {
        return Action::make('send')->label('Kirim')->icon(Heroicon::OutlinedPaperAirplane)->color('primary')
            ->visible(fn (PurchaseOrder $record): bool => $record->isDraft() && PurchaseOrderResource::canViewAny())
            ->requiresConfirmation()
            ->modalHeading('Kirim purchase order')
            ->modalDescription(fn (PurchaseOrder $record): HtmlString => Layout::confirmText(
                'Tandai <strong>'.e($record->number).'</strong> senilai '.e(Money::format($record->total_cost)).' sudah dipesan ke supplier?',
                'Isi PO terkunci. Barang yang datang dicatat lewat Terima barang.',
            ))
            ->modalSubmitActionLabel('Kirim')
            ->action(function (PurchaseOrder $record, Action $action): void {
                RunBusinessAction::run(fn () => app(SendPurchaseOrder::class)->handle($record, self::user()), $action);
                Notification::make()->success()->title('PO dikirim')->send();
                $action->redirect(PurchaseOrderResource::getUrl('view', ['record' => $record]));
            });
    }

    public static function receive(): Action
    {
        return Action::make('receive')->label('Terima barang')->icon(Heroicon::OutlinedArrowDownTray)->color('primary')
            ->visible(fn (PurchaseOrder $record): bool => $record->status->isOpen() && StockReceiptResource::canViewAny())
            ->requiresConfirmation()
            ->modalHeading('Terima barang')
            ->modalDescription('Draf Stok masuk dibuat dari sisa qty PO. Sesuaikan qty & harga sesuai barang yang datang, lalu Posting.')
            ->modalSubmitActionLabel('Buat stok masuk')
            ->action(function (PurchaseOrder $record, Action $action): void {
                $receipt = RunBusinessAction::run(fn () => app(ReceivePurchaseOrder::class)->handle($record), $action);
                Notification::make()->success()->title('Draf stok masuk '.$receipt->number.' dibuat')->send();
                $action->redirect(StockReceiptResource::getUrl('edit', ['record' => $receipt]));
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')->label('Batalkan')->icon(Heroicon::OutlinedXCircle)->color('danger')
            ->visible(fn (PurchaseOrder $record): bool => $record->status->isOpen() && PurchaseOrderResource::canViewAny())
            ->modalHeading('Batalkan purchase order')
            ->modalDescription('Sisa barang tidak ditunggu lagi. Stok masuk yang sudah diposting tetap.')
            ->schema([TextInput::make('reason')->label('Alasan')->required()->maxLength(255)->placeholder('mis. supplier kehabisan barang')])
            ->modalSubmitActionLabel('Batalkan PO')
            ->action(function (PurchaseOrder $record, array $data, Action $action): void {
                RunBusinessAction::run(fn () => app(CancelPurchaseOrder::class)->handle($record, self::user(), (string) $data['reason']), $action);
                Notification::make()->success()->title('PO dibatalkan')->send();
                $action->redirect(PurchaseOrderResource::getUrl('view', ['record' => $record]));
            });
    }

    public static function delete(): Action
    {
        return Action::make('delete')->label('Hapus draf')->icon(Heroicon::OutlinedTrash)->color('gray')
            ->visible(fn (PurchaseOrder $record): bool => $record->isDraft() && PurchaseOrderResource::canViewAny())
            ->requiresConfirmation()
            ->modalHeading('Hapus draf PO')
            ->modalSubmitActionLabel('Hapus')
            ->action(function (PurchaseOrder $record, Action $action): void {
                RunBusinessAction::run(fn () => app(DeletePurchaseOrder::class)->handle($record), $action);
                Notification::make()->success()->title('Draf dihapus')->send();
                $action->redirect(PurchaseOrderResource::getUrl('index'));
            });
    }

    private static function user(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }
}
