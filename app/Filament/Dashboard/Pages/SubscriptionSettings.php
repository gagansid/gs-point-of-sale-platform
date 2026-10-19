<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages;

use App\Actions\Billing\CreateAddonInvoice;
use App\Actions\Billing\CreateInvoice;
use App\Actions\Billing\StartInvoicePayment;
use App\Actions\Billing\StopTenantAddon;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\TenantStatus;
use App\Exceptions\BusinessException;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\Addon;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\User;
use App\Services\Billing\InvoiceQuote;
use App\Services\Payment\MidtransClient;
use App\Support\CurrentOutlet;
use App\Support\Edition;
use App\Support\Money;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Pengaturan → Langganan (ADR 0024, Q109; izin billing.manage = owner): paket & masa aktif saat ini, buat tagihan
 * (pilih paket & siklus), add-on (tambah prorata, berhenti/lanjutkan; ADR 0025 C2), bayar online (Midtrans vendor)
 * atau transfer manual, riwayat tagihan. Hanya edisi SaaS.
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
        return Tenant::query()->with(['plan', 'nextPlan'])->findOrFail(TenantContext::idOrFail());
    }

    /** @return Collection<int, Plan> */
    public function plans(): Collection
    {
        $current = $this->tenant()->plan_id;

        // Publik & di dalam periode (promo), atau paket bisnis ini sendiri
        return Plan::query()->where('is_active', true)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('is_public', true)->withinPeriod())->when($current !== null, fn ($q) => $q->orWhere('id', $current)))
            ->orderBy('sort_order')->orderBy('price_monthly')->get();
    }

    /** @return Collection<int, SubscriptionInvoice> */
    public function invoices(): Collection
    {
        return SubscriptionInvoice::query()->with('items')->latest()->limit(24)->get();
    }

    /**
     * Add-on yang bisa dibeli owner sekarang (ADR 0025 C2): aktif & publik; add-on fitur yang sudah termasuk paket atau
     * sudah aktif tidak ditawarkan.
     *
     * @return Collection<int, Addon>
     */
    public function purchasableAddons(): Collection
    {
        $tenant = $this->tenant();
        $planFeatures = $tenant->plan->features ?? [];
        $active = TenantAddon::query()->where('status', TenantAddon::ACTIVE)->pluck('addon_id')->all();

        return Addon::query()->where('is_active', true)->where('is_public', true)->orderBy('sort_order')->orderBy('name')->get()
            ->reject(fn (Addon $a): bool => ! $a->isQuota() && (in_array($a->feature_key, $planFeatures, true) || in_array($a->id, $active, true)))
            ->values();
    }

    /** Add-on hanya bisa ditambah selagi langganan berbayar berjalan (trial: lewat tagihan paket dulu). */
    public function canBuyAddons(): bool
    {
        $tenant = $this->tenant();

        return $tenant->status === TenantStatus::Active && $tenant->plan_id !== null && $tenant->billing_cycle !== null
            && $tenant->subscription_ends_at?->isFuture() === true && $this->purchasableAddons()->isNotEmpty();
    }

    public function addAddonAction(): Action
    {
        return Action::make('addAddon')->label('Tambah add-on')->icon(Heroicon::OutlinedPuzzlePiece)->color('gray')
            ->visible(fn (): bool => $this->canBuyAddons())
            ->modalHeading('Tambah add-on')
            ->modalDescription('Dibayar prorata untuk sisa masa aktif, aktif setelah lunas. Perpanjangan berikutnya otomatis memuat add-on ini. Tagihan menunggu lain akan dibatalkan')
            ->modalSubmitActionLabel('Buat tagihan')
            ->schema([
                Radio::make('addon_id')->label('Add-on')->required()->live()
                    ->options(fn (): array => $this->purchasableAddons()->mapWithKeys(fn (Addon $a): array => [$a->id => $a->name])->all())
                    ->descriptions(fn (): array => $this->purchasableAddons()->mapWithKeys(fn (Addon $a): array => [$a->id => $a->benefit().' · '.Money::format($a->price_monthly).'/bulan · '.Money::format($a->price_yearly).'/tahun'])->all()),
                TextInput::make('quantity')->label('Jumlah unit')->integer()->minValue(1)->maxValue(999)->default(1)->live()
                    ->visible(fn (Get $get): bool => $this->purchasableAddons()->firstWhere('id', $get('addon_id'))?->isQuota() === true),
                Placeholder::make('quote')->label('Tagihan')
                    ->content(fn (Get $get): string => $this->addonQuoteText($get('addon_id'), $get('quantity'))),
            ])
            ->action(function (array $data): void {
                abort_unless(self::canAccess(), 403);
                $addon = $this->purchasableAddons()->firstWhere('id', $data['addon_id']) ?? abort(404);

                try {
                    $invoice = app(CreateAddonInvoice::class)->handle($this->tenant(), $addon, (int) ($data['quantity'] ?? 1));
                } catch (BusinessException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Tagihan '.$invoice->number.' dibuat')->send();
            });
    }

    public function addonQuoteText(mixed $addonId, mixed $quantity): string
    {
        $addon = $this->purchasableAddons()->firstWhere('id', $addonId);
        if ($addon === null) {
            return 'Pilih add-on';
        }

        try {
            $quote = InvoiceQuote::forAddon($this->tenant(), $addon, $addon->isQuota() ? max(1, (int) $quantity) : 1);
        } catch (BusinessException $e) {
            return $e->getMessage();
        }

        return $quote->summary($this->tenant()->plan ?? new Plan, CurrentOutlet::timezone());
    }

    /** Berhenti di akhir masa aktif (argumen: id baris tenant_addons). */
    public function stopAddonAction(): Action
    {
        return Action::make('stopAddon')->label('Berhenti')->color('danger')->link()->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Berhenti berlangganan add-on?')
            ->modalDescription('Add-on tetap berlaku sampai akhir masa aktif dan tidak ikut perpanjangan. Tidak ada pengembalian dana. Data yang sudah ada tidak dihapus')
            ->action(fn (array $arguments) => $this->toggleAddon((string) ($arguments['id'] ?? ''), stop: true));
    }

    public function resumeAddonAction(): Action
    {
        return Action::make('resumeAddon')->label('Lanjutkan')->link()->size('sm')
            ->action(fn (array $arguments) => $this->toggleAddon((string) ($arguments['id'] ?? ''), stop: false));
    }

    private function toggleAddon(string $id, bool $stop): void
    {
        abort_unless(self::canAccess(), 403);
        // Tenant scope: baris add-on bisnis lain tidak ditemukan
        $row = TenantAddon::query()->findOrFail($id);
        app(StopTenantAddon::class)->handle($row, $stop);
        Notification::make()->success()->title($stop ? 'Add-on akan berhenti di akhir masa aktif' : 'Add-on dilanjutkan')->send();
    }

    /**
     * Tagihan menunggu yang masih bisa dibayar: satu tagihan paket/add-on + tagihan manual admin (bisa lebih dari satu).
     *
     * @return Collection<int, SubscriptionInvoice>
     */
    public function pendingInvoices(): Collection
    {
        return SubscriptionInvoice::query()->with('items')->where('status', InvoiceStatus::Pending)->where('due_at', '>', now())
            ->orderBy('due_at')->get();
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
            ->modalDescription('Upgrade ke paket lebih mahal: bayar selisih sisa masa aktif, paket langsung berganti. Lainnya: perpanjangan dari akhir masa aktif sekarang')
            ->fillForm(fn (): array => [
                'plan_id' => $this->tenant()->plan_id ?? $this->plans()->first()?->id,
                'billing_cycle' => ($this->tenant()->billing_cycle ?? BillingCycle::Monthly)->value,
            ])
            ->schema([
                Radio::make('plan_id')->label('Paket')->required()->live()
                    ->options(fn (): array => $this->plans()->mapWithKeys(fn (Plan $p): array => [$p->id => $p->name])->all())
                    ->descriptions(fn (): array => $this->plans()->mapWithKeys(fn (Plan $p): array => [$p->id => Money::format($p->price_monthly).'/bulan · '.Money::format($p->price_yearly).'/tahun'.($p->limits ? ' · '.$p->limitsSummary() : '')])->all()),
                ToggleButtons::make('billing_cycle')->label('Siklus')->required()->inline()->options(BillingCycle::class)->live(),
                Placeholder::make('quote')->label('Tagihan')
                    ->content(fn (Get $get): string => $this->quoteText($get('plan_id'), $get('billing_cycle'))),
            ])
            ->action(function (array $data): void {
                abort_unless(self::canAccess(), 403);
                $plan = $this->plans()->firstWhere('id', $data['plan_id']) ?? abort(404);
                $cycle = self::cycle($data['billing_cycle']) ?? abort(422);

                try {
                    $invoice = app(CreateInvoice::class)->handle($this->tenant(), $plan, $cycle);
                } catch (BusinessException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Tagihan '.$invoice->number.' dibuat')->send();
            });
    }

    /** Pratinjau nominal & efek tagihan di modal (sama dengan CreateInvoice, lewat InvoiceQuote). */
    public function quoteText(mixed $planId, mixed $cycle): string
    {
        $plan = $this->plans()->firstWhere('id', $planId);
        $cycle = self::cycle($cycle);

        if ($plan === null || $cycle === null) {
            return 'Pilih paket & siklus';
        }

        return InvoiceQuote::for($this->tenant(), $plan, $cycle)->summary($plan, CurrentOutlet::timezone());
    }

    private static function cycle(mixed $value): ?BillingCycle
    {
        return $value instanceof BillingCycle ? $value : BillingCycle::tryFrom((string) $value);
    }

    public function payOnlineAction(): Action
    {
        return Action::make('payOnline')->label('Bayar online')->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->visible(fn (): bool => $this->pendingInvoices()->isNotEmpty() && $this->onlinePaymentAvailable())
            // Argumen id: tagihan menunggu milik bisnis ini (scope tenant)
            ->action(function (Action $action, array $arguments): void {
                abort_unless(self::canAccess(), 403);
                $user = auth()->user();
                abort_unless($user instanceof User, 403);
                $invoice = $this->pendingInvoices()->firstWhere('id', (string) ($arguments['id'] ?? '')) ?? abort(404);

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
