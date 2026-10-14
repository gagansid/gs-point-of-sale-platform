<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Shifts\Actions;

use App\Actions\Shift\CloseShift;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Forms\MoneyInput;
use App\Models\Shift;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Tutup paksa shift kasir yang lupa ditutup / device rusak (shift.force_close).
 */
final class ForceCloseShiftAction
{
    public static function make(): Action
    {
        return Action::make('forceClose')
            ->label('Tutup paksa')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('warning')
            ->visible(fn (Shift $record): bool => $record->isOpen() && (self::user()?->can('shift.force_close') ?? false))
            ->modalHeading('Tutup paksa shift?')
            ->modalDescription('Hitung uang di laci lalu isi kas aktual. Selisih dihitung otomatis')
            ->modalSubmitActionLabel('Tutup shift')
            ->schema([
                MoneyInput::make('actual_cash')->label('Kas aktual')->required(),
                // ADR 0017: sisa (kas aktual − disetor) menjadi kas awal shift berikutnya di perangkat itu
                MoneyInput::make('cash_deposited')->label('Disetor')
                    ->helperText('Uang yang diambil dari laci. Kosongkan = kas aktual − modal laci tetap outlet (atau 0)'),
                Textarea::make('note')->label('Catatan')->placeholder('Kasir lupa menutup shift')->required()->maxLength(255)->rows(2),
            ])
            ->action(function (Shift $record, array $data, Action $action): void {
                RunBusinessAction::run(fn () => app(CloseShift::class)->handle(
                    self::user() ?? abort(403),
                    $record,
                    device: null,
                    actualCash: Money::of((string) $data['actual_cash']),
                    cashDeposited: filled($data['cash_deposited'] ?? null) ? Money::of((string) $data['cash_deposited']) : null,
                    note: (string) $data['note'],
                    force: true,
                ), $action);

                Notification::make()->success()->title('Shift berhasil ditutup')->send();
            });
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
