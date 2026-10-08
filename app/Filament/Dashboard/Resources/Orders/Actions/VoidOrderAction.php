<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Orders\Actions;

use App\Actions\Order\VoidOrder;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Filament\Shared\Layout;
use App\Models\Order;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Void dari dashboard (modal.md, varian danger). Owner/manager punya order.void sehingga tidak
 * perlu PIN; aturan lain (shift terbuka, stok kembali) sama dengan API karena memakai VoidOrder.
 */
final class VoidOrderAction
{
    public static function make(): Action
    {
        return Action::make('void')
            ->label('Void')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Order $record): bool => $record->status !== OrderStatus::Voided && (self::user()?->can('order.void') ?? false))
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->modalHeading('Void transaksi')
            ->modalDescription(fn (Order $record): HtmlString => Layout::confirmText(
                'Yakin ingin membatalkan transaksi <strong>'.e($record->order_number).'</strong>?',
                'Stok dikembalikan dan pembayaran dibatalkan. Transaksi tidak bisa dipulihkan.',
            ))
            ->modalSubmitActionLabel('Void transaksi')
            ->schema([
                Textarea::make('reason')->label('Alasan')->required()->maxLength(255)->rows(2),
            ])
            ->action(function (Order $record, array $data, Action $action): void {
                try {
                    app(VoidOrder::class)->handle(self::user() ?? abort(403), $record, (string) $data['reason'], null);
                } catch (BusinessException $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                    $action->halt();
                }

                Notification::make()->success()->title('Transaksi berhasil dibatalkan')->send();
            });
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
