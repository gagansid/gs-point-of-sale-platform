<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages;

use App\Actions\Billing\CreateInvoice;
use App\Actions\Billing\StartInvoicePayment;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Exceptions\BusinessException;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payment\MidtransClient;
use App\Support\Edition;
use App\Support\Money;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Pengaturan → Langganan (ADR 0024, Q109; izin billing.manage = owner): paket & masa aktif saat ini, buat tagihan
 * (pilih paket & siklus), bayar online (Midtrans vendor) atau transfer manual, riwayat tagihan. Hanya edisi SaaS.
 */
final class SubscriptionSettings extends Page
{
    use HasIconBreadcrumbs;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Langganan';

    protected static ?string $title = 'Langganan';

    protected static ?string $slug = 'settings/subscription';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.dashboard.pages.subscription';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        // Tetap bisa dibuka saat hanya-baca: justru untuk memperpanjang
        return Edition::isSaas() && $user instanceof User && $user->hasPermission('billing.manage');
    }

    public function tenant(): Tenant
    {
        return Tenant::query()->with('plan')->findOrFail(TenantContext::idOrFail());
    }

    /** @return Collection<int, Plan> */
    public function plans(): Collection
    {
        $current = $this->tenant()->plan_id;

        return Plan::query()->where('is_active', true)
            ->where(fn ($q) => $q->where('is_public', true)->when($current !== null, fn ($q) => $q->orWhere('id', $current)))
            ->orderBy('sort_order')->orderBy('price_monthly')->get();
    }

    /** @return Collection<int, SubscriptionInvoice> */
    public function invoices(): Collection
    {
        return SubscriptionInvoice::query()->latest()->limit(24)->get();
    }

    public function pendingInvoice(): ?SubscriptionInvoice
    {
        return SubscriptionInvoice::query()->where('status', InvoiceStatus::Pending)->where('due_at', '>', now())->latest()->first();
    }

    public function onlinePaymentAvailable(): bool
    {
        return MidtransClient::vendor() !== null;
    }

    public function createInvoiceAction(): Action
    {
        return Action::make('createInvoice')->label('Buat tagihan')->icon(Heroicon::OutlinedDocumentPlus)
            ->visible(fn (): bool => $this->plans()->isNotEmpty())
            ->modalHeading('Buat tagihan langganan')
            ->modalDescription('Paket baru berlaku setelah tagihan lunas; masa aktif ditambah dari akhir masa aktif sekarang')
            ->fillForm(fn (): array => [
                'plan_id' => $this->tenant()->plan_id ?? $this->plans()->first()?->id,
                'billing_cycle' => ($this->tenant()->billing_cycle ?? BillingCycle::Monthly)->value,
            ])
            ->schema([
                Radio::make('plan_id')->label('Paket')->required()
                    ->options(fn (): array => $this->plans()->mapWithKeys(fn (Plan $p): array => [$p->id => $p->name])->all())
                    ->descriptions(fn (): array => $this->plans()->mapWithKeys(fn (Plan $p): array => [$p->id => Money::format($p->price_monthly).'/bulan · '.Money::format($p->price_yearly).'/tahun'.($p->max_outlets !== null ? ' · maks. '.$p->max_outlets.' outlet' : '')])->all()),
                ToggleButtons::make('billing_cycle')->label('Siklus')->required()->inline()->options(BillingCycle::class),
            ])
            ->action(function (array $data): void {
                abort_unless(self::canAccess(), 403);
                $plan = $this->plans()->firstWhere('id', $data['plan_id']) ?? abort(404);
                $cycle = $data['billing_cycle'] instanceof BillingCycle ? $data['billing_cycle'] : BillingCycle::from((string) $data['billing_cycle']);

                try {
                    $invoice = app(CreateInvoice::class)->handle($this->tenant(), $plan, $cycle);
                } catch (BusinessException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Tagihan '.$invoice->number.' dibuat')->send();
            });
    }

    public function payOnlineAction(): Action
    {
        return Action::make('payOnline')->label('Bayar online')->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->visible(fn (): bool => $this->pendingInvoice() !== null && $this->onlinePaymentAvailable())
            ->action(function (Action $action): void {
                abort_unless(self::canAccess(), 403);
                $user = auth()->user();
                abort_unless($user instanceof User, 403);
                $invoice = $this->pendingInvoice() ?? abort(404);

                try {
                    $url = app(StartInvoicePayment::class)->handle($invoice, $user, self::getUrl());
                } catch (BusinessException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                $action->redirect($url);
            });
    }
}
