<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Actions;

use App\Actions\Billing\CreateInvoice;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Services\Billing\InvoiceQuote;
use App\Support\Edition;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;

/**
 * Buat tagihan untuk bisnis dari panel /admin (ADR 0024, Q109): semua paket aktif termasuk paket khusus (non-publik),
 * nominal & jenis sama dengan modal owner (InvoiceQuote), opsional email ke owner.
 */
final class TenantInvoiceAction
{
    public static function make(): Action
    {
        return Action::make('createInvoice')->label('Buat tagihan')->icon(Heroicon::OutlinedDocumentPlus)->color('gray')
            // Tanpa paket aktif tidak ada yang bisa ditagihkan
            ->visible(fn (): bool => Edition::isSaas() && Plan::query()->where('is_active', true)->exists())
            ->modalHeading('Buat tagihan langganan')
            ->modalSubmitActionLabel('Buat tagihan')
            ->modalDescription(fn (Tenant $record): string => self::pendingNote($record))
            ->fillForm(fn (Tenant $record): array => [
                'plan_id' => $record->plan_id,
                'billing_cycle' => ($record->billing_cycle ?? BillingCycle::Monthly)->value,
                'notify_owners' => true,
            ])
            ->schema([
                Select::make('plan_id')->label('Paket')->required()->live()
                    ->options(fn (): array => Plan::query()->where('is_active', true)->orderBy('sort_order')->get()
                        ->mapWithKeys(fn (Plan $p): array => [$p->id => $p->name.($p->is_public ? '' : ' (khusus)').' · '.Money::format($p->price_monthly).'/bulan'])
                        ->all()),
                ToggleButtons::make('billing_cycle')->label('Siklus')->required()->inline()->options(BillingCycle::class)->live(),
                Placeholder::make('quote')->label('Tagihan')
                    ->content(fn (Get $get, Tenant $record): string => self::quoteText($record, $get('plan_id'), $get('billing_cycle'))),
                Toggle::make('notify_owners')->label('Kirim email tagihan ke owner'),
            ])
            ->action(function (Tenant $record, array $data, Action $action): void {
                $admin = auth('admin')->user();
                abort_unless($admin instanceof Admin, 403);
                $plan = Plan::query()->findOrFail($data['plan_id']);
                $cycle = self::cycle($data['billing_cycle']) ?? abort(422);

                $invoice = RunBusinessAction::run(
                    fn (): SubscriptionInvoice => app(CreateInvoice::class)->handle($record, $plan, $cycle, $admin, (bool) ($data['notify_owners'] ?? false)),
                    $action,
                );
                Notification::make()->success()->title('Tagihan '.$invoice->number.' dibuat')->send();
            });
    }

    private static function quoteText(Tenant $tenant, mixed $planId, mixed $cycle): string
    {
        $plan = filled($planId) ? Plan::query()->find($planId) : null;
        $cycle = self::cycle($cycle);

        if ($plan === null || $cycle === null) {
            return 'Pilih paket & siklus';
        }

        return InvoiceQuote::for($tenant, $plan, $cycle)->summary($plan, FilamentTimezone::get());
    }

    /** Peringatan: satu tagihan menunggu per bisnis, yang lama dibatalkan. */
    private static function pendingNote(Tenant $tenant): string
    {
        $pending = $tenant->subscriptionInvoices()->where('status', InvoiceStatus::Pending)->value('number');

        return $pending !== null
            ? "Tagihan menunggu {$pending} akan dibatalkan dan diganti tagihan baru"
            : 'Upgrade ke paket lebih mahal: bayar selisih sisa masa aktif. Lainnya: perpanjangan dari akhir masa aktif';
    }

    private static function cycle(mixed $value): ?BillingCycle
    {
        return $value instanceof BillingCycle ? $value : BillingCycle::tryFrom((string) $value);
    }
}
