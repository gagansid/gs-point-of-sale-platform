<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Members\Actions;

use App\Actions\Member\PayReceivable;
use App\Actions\Member\SetMemberCreditLimit;
use App\Enums\PaymentCategory;
use App\Filament\Dashboard\Resources\Members\MemberResource;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Forms\MoneyInput;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\PaymentMethod;
use App\Models\ReceivableEntry;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * Aksi kasbon member (ADR 0023) — dipakai di Pelanggan → Member dan Pelanggan → Kasbon. Semua memanggil Action bisnis.
 */
final class ReceivableActions
{
    public static function history(): Action
    {
        return Action::make('receivables')->label('Riwayat kasbon')->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn (Member $record): bool => MemberSetting::current()->credit_enabled || Money::isPositive($record->receivable_balance))
            ->modalHeading(fn (Member $record): string => 'Kasbon · '.$record->name)
            ->modalContent(fn (Member $record) => view('filament.dashboard.members.receivables', [
                'member' => $record,
                'entries' => ReceivableEntry::query()->with(['user:id,name', 'paymentMethod:id,name'])->where('member_id', $record->id)->latest()->limit(100)->get(),
            ]))
            ->modalSubmitAction(false)->modalCancelActionLabel('Tutup');
    }

    public static function creditLimit(): Action
    {
        return Action::make('creditLimit')->label('Batas kasbon')->icon(Heroicon::OutlinedScale)
            ->visible(fn (Member $record): bool => MemberSetting::current()->credit_enabled && MemberResource::canEdit($record))
            ->modalHeading(fn (Member $record): string => 'Batas kasbon · '.$record->name)
            ->fillForm(fn (Member $record): array => ['credit_limit' => $record->credit_limit])
            ->schema([
                MoneyInput::make('credit_limit')->label('Batas')
                    ->helperText(fn (): string => 'Kosong = batas bawaan '.(MemberSetting::current()->default_credit_limit !== null ? Money::format(MemberSetting::current()->default_credit_limit) : '(tidak ada)').'; 0 = tidak boleh kasbon'),
            ])
            ->action(function (Member $record, array $data): void {
                abort_unless(MemberResource::canEdit($record), 403);
                app(SetMemberCreditLimit::class)->handle($record, filled($data['credit_limit'] ?? null) ? (string) $data['credit_limit'] : null);
                Notification::make()->success()->title('Batas kasbon disimpan')->send();
            });
    }

    public static function pay(): Action
    {
        return Action::make('payReceivable')->label('Catat pelunasan')->icon(Heroicon::OutlinedCheckBadge)
            ->visible(fn (Member $record): bool => Money::isPositive($record->receivable_balance) && (auth()->user()?->can('member.manage') ?? false))
            ->modalHeading(fn (Member $record): string => 'Pelunasan kasbon · '.$record->name)
            ->modalDescription(fn (Member $record): string => 'Kasbon '.Money::format($record->receivable_balance).'. Dicatat di luar laci kasir; untuk tunai di kasir pakai menu Kasbon di kasir web')
            ->fillForm(fn (Member $record): array => ['amount' => $record->receivable_balance])
            ->schema([
                MoneyInput::make('amount')->label('Nominal')->required(),
                Select::make('payment_method_id')->label('Metode')->required()->native(false)
                    ->options(fn (): array => PaymentMethod::query()->where('is_active', true)
                        ->whereNotIn('category', [PaymentCategory::Points, PaymentCategory::Online, PaymentCategory::Receivable])
                        ->orderBy('sort_order')->pluck('name', 'id')->all()),
                TextInput::make('note')->label('Catatan')->maxLength(255),
            ])
            ->action(function (Member $record, array $data, Action $action): void {
                $user = auth()->user();
                abort_unless($user instanceof User && $user->can('member.manage'), 403);
                RunBusinessAction::run(fn () => app(PayReceivable::class)->handle($user, $record, null, (string) Str::uuid7(),
                    Money::of((string) $data['amount']), (string) $data['payment_method_id'], $data['note'] ?? null), $action);
                Notification::make()->success()->title('Pelunasan dicatat')->send();
            });
    }
}
