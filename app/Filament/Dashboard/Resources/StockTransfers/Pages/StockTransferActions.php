<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockTransfers\Pages;

use App\Actions\Stock\CancelStockTransfer;
use App\Actions\Stock\DeleteStockTransfer;
use App\Actions\Stock\ReceiveStockTransfer;
use App\Actions\Stock\SendStockTransfer;
use App\Enums\StockTransferStatus;
use App\Filament\Dashboard\Resources\StockTransfers\StockTransferResource;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Layout;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/** Aksi transfer (ADR 0015): kirim & batal (pemegang outlet asal), terima (pemegang outlet tujuan), hapus draf. */
final class StockTransferActions
{
    public static function send(): Action
    {
        return Action::make('send')->label('Kirim')->icon(Heroicon::OutlinedPaperAirplane)->color('primary')
            ->visible(fn (StockTransfer $record): bool => $record->isDraft() && self::holds($record->from_outlet_id))
            ->requiresConfirmation()
            ->modalHeading('Kirim transfer')
            ->modalDescription(fn (StockTransfer $record): HtmlString => Layout::confirmText(
                'Kirim <strong>'.e($record->number).'</strong>?',
                'Stok di outlet asal langsung berkurang. Stok outlet tujuan bertambah setelah barang diterima di sana.',
            ))
            ->modalSubmitActionLabel('Kirim')
            ->action(function (StockTransfer $record, Action $action): void {
                RunBusinessAction::run(fn () => app(SendStockTransfer::class)->handle($record, self::user()), $action);
                Notification::make()->success()->title('Transfer dikirim')->send();
                $action->redirect(StockTransferResource::getUrl('view', ['record' => $record]));
            });
    }

    public static function receive(): Action
    {
        return Action::make('receive')->label('Terima')->icon(Heroicon::OutlinedInboxArrowDown)->color('success')
            ->visible(fn (StockTransfer $record): bool => $record->status === StockTransferStatus::InTransit && self::holds($record->to_outlet_id))
            ->modalHeading('Terima transfer')
            ->modalDescription('Periksa barang yang datang. Isi qty yang benar-benar diterima; selisih dicatat sebagai hilang/rusak di jalan')
            ->modalWidth('lg')
            ->fillForm(fn (StockTransfer $record): array => ['received' => $record->items->mapWithKeys(fn (StockTransferItem $i): array => [$i->id => $i->qty_sent])->all()])
            ->schema(fn (StockTransfer $record): array => $record->items->map(fn (StockTransferItem $i) => TextInput::make("received.{$i->id}")
                ->label($i->product_name.' (dikirim '.$i->qty_sent.')')->integer()->required()->minValue(0)->maxValue($i->qty_sent))->all())
            ->modalSubmitActionLabel('Terima')
            ->action(function (StockTransfer $record, array $data, Action $action): void {
                RunBusinessAction::run(fn () => app(ReceiveStockTransfer::class)->handle($record, self::user(), (array) ($data['received'] ?? [])), $action);
                Notification::make()->success()->title('Transfer diterima')->send();
                $action->redirect(StockTransferResource::getUrl('view', ['record' => $record]));
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')->label('Batalkan')->icon(Heroicon::OutlinedXCircle)->color('danger')
            ->visible(fn (StockTransfer $record): bool => $record->status === StockTransferStatus::InTransit && self::holds($record->from_outlet_id))
            ->modalHeading('Batalkan transfer')
            ->modalDescription('Stok dikembalikan ke outlet asal sebesar qty yang dikirim')
            ->schema([TextInput::make('reason')->label('Alasan')->required()->maxLength(255)])
            ->modalSubmitActionLabel('Batalkan transfer')
            ->action(function (StockTransfer $record, array $data, Action $action): void {
                RunBusinessAction::run(fn () => app(CancelStockTransfer::class)->handle($record, self::user(), (string) $data['reason']), $action);
                Notification::make()->success()->title('Transfer dibatalkan')->send();
                $action->redirect(StockTransferResource::getUrl('view', ['record' => $record]));
            });
    }

    public static function delete(): Action
    {
        return Action::make('delete')->label('Hapus draf')->icon(Heroicon::OutlinedTrash)->color('gray')
            ->visible(fn (StockTransfer $record): bool => $record->isDraft() && self::holds($record->from_outlet_id))
            ->requiresConfirmation()
            ->modalHeading('Hapus draf transfer')
            ->modalSubmitActionLabel('Hapus')
            ->action(function (StockTransfer $record, Action $action): void {
                RunBusinessAction::run(fn () => app(DeleteStockTransfer::class)->handle($record, self::user()), $action);
                Notification::make()->success()->title('Draf dihapus')->send();
                $action->redirect(StockTransferResource::getUrl('index'));
            });
    }

    private static function holds(string $outletId): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('stock.transfer') && $user->canAccessOutlet($outletId);
    }

    private static function user(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }
}
