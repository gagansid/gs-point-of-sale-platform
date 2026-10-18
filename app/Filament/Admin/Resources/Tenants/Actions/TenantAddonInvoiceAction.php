<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Actions;

use App\Actions\Billing\CreateAddonInvoice;
use App\Exceptions\BusinessException;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Models\Addon;
use App\Models\Admin;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Services\Billing\InvoiceQuote;
use App\Support\Edition;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;

/**
 * Tagih add-on dari detail bisnis (ADR 0025 C2): prorata sisa masa aktif, termasuk add-on khusus (non-publik);
 * add-on aktif setelah lunas. Untuk memasang tanpa tagihan pakai Atur add-on.
 */
final class TenantAddonInvoiceAction
{
    public static function make(): Action
    {
        return Action::make('addonInvoice')->label('Tagih add-on')->icon(Heroicon::OutlinedPuzzlePiece)->color('gray')
            ->visible(fn (Tenant $record): bool => Edition::isSaas() && $record->plan_id !== null && Addon::query()->where('is_active', true)->exists())
            ->modalHeading('Tagih add-on')
            ->modalDescription('Prorata untuk sisa masa aktif; aktif setelah lunas. Tagihan menunggu lain dibatalkan')
            ->modalSubmitActionLabel('Buat tagihan')
            ->schema([
                Select::make('addon_id')->label('Add-on')->required()->live()
                    ->options(fn (): array => Addon::query()->where('is_active', true)->orderBy('sort_order')->get()
                        ->mapWithKeys(fn (Addon $a): array => [$a->id => $a->name.($a->is_public ? '' : ' (khusus)').' · '.Money::format($a->price_monthly).'/bulan'])->all()),
                TextInput::make('quantity')->label('Jumlah unit')->integer()->minValue(1)->maxValue(999)->default(1)->live()
                    ->visible(fn (Get $get): bool => Addon::query()->find($get('addon_id'))?->isQuota() === true),
                Placeholder::make('quote')->label('Tagihan')
                    ->content(fn (Get $get, Tenant $record): string => self::quoteText($record, $get('addon_id'), $get('quantity'))),
                Toggle::make('notify_owners')->label('Kirim email tagihan ke owner')->default(true),
            ])
            ->action(function (Tenant $record, array $data, Action $action): void {
                $admin = auth('admin')->user();
                abort_unless($admin instanceof Admin, 403);
                $addon = Addon::query()->findOrFail($data['addon_id']);

                $invoice = RunBusinessAction::run(
                    fn (): SubscriptionInvoice => app(CreateAddonInvoice::class)->handle($record, $addon, (int) ($data['quantity'] ?? 1), $admin, (bool) ($data['notify_owners'] ?? false)),
                    $action,
                );
                Notification::make()->success()->title('Tagihan '.$invoice->number.' dibuat')->send();
            });
    }

    private static function quoteText(Tenant $tenant, mixed $addonId, mixed $quantity): string
    {
        $addon = filled($addonId) ? Addon::query()->find($addonId) : null;
        if ($addon === null || $tenant->plan === null) {
            return 'Pilih add-on';
        }

        try {
            return InvoiceQuote::forAddon($tenant, $addon, $addon->isQuota() ? max(1, (int) $quantity) : 1)->summary($tenant->plan, FilamentTimezone::get());
        } catch (BusinessException $e) {
            return $e->getMessage();
        }
    }
}
