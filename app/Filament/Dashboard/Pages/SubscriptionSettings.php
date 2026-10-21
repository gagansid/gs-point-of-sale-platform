<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages;

use App\Actions\Billing\CreateAddonInvoice;
use App\Actions\Billing\CreateInvoice;
use App\Actions\Billing\StartInvoicePayment;
use App\Actions\Billing\StopTenantAddon;
use App\Actions\Billing\SubmitPaymentProof;
use App\Enums\BillingCycle;
use App\Enums\InvoiceKind;
use App\Enums\InvoicePaymentMethod;
use App\Enums\InvoiceStatus;
use App\Enums\TenantStatus;
use App\Exceptions\BusinessException;
use App\Filament\Shared\Columns\MoneyColumn;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Filters\DateRangeFilter;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Filament\Shared\Tables\TableHeading;
use App\Models\Addon;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\User;
use App\Services\Billing\InvoicePdf;
use App\Services\Billing\InvoiceQuote;
use App\Services\Payment\MidtransClient;
use App\Support\CurrentOutlet;
use App\Support\Edition;
use App\Support\Money;
use App\Support\SystemSettings;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Pengaturan → Langganan (ADR 0024, Q109; izin billing.manage = owner): paket & masa aktif saat ini, buat tagihan
 * (pilih paket & siklus), add-on (tambah prorata, berhenti/lanjutkan; ADR 0025 C2), bayar online (Midtrans vendor)
 * atau transfer manual, riwayat tagihan. Hanya edisi SaaS.
 */
final class SubscriptionSettings extends Page implements HasTable
{
    use HasIconBreadcrumbs;
    use InteractsWithTable;

    /**
     * Tanpa #[Url(as: 'filters')]: filter Riwayat tagihan disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

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

    /**
     * Riwayat tagihan (tabel standar): cari nomor/judul/paket, filter status, jenis, metode & tanggal, paginasi global.
     * Scope tenant: hanya tagihan bisnis ini.
     */
    public function table(Table $table): Table
    {
        $table = $table
            ->query(fn (): Builder => SubscriptionInvoice::query()->with('items'))
            ->heading(TableHeading::make('Riwayat tagihan', Heroicon::OutlinedClock))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('Nomor')->fontFamily(FontFamily::Mono)->size('xs')->sortable()->copyable()
                    ->copyMessage('Nomor disalin')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q) => $q
                        ->where('number', 'like', '%'.$search.'%')->orWhere('title', 'like', '%'.$search.'%')->orWhere('plan_name', 'like', '%'.$search.'%'))),
                TextColumn::make('created_at')->label('Tanggal')->date('j M Y')->timezone(CurrentOutlet::timezone())->sortable(),
                TextColumn::make('rincian')->label('Rincian')->wrap()
                    ->state(fn (SubscriptionInvoice $record): string => $record->description())
                    ->description(fn (SubscriptionInvoice $record): ?string => $record->period_start !== null
                        ? 'Periode '.$record->period_start->setTimezone(CurrentOutlet::timezone())->locale('id')->translatedFormat('j M Y').' – '.$record->period_end?->setTimezone(CurrentOutlet::timezone())->locale('id')->translatedFormat('j M Y')
                        : null)
                    ->extraCellAttributes(['class' => 'gs-ta-cell-wide']),
                TextColumn::make('kind')->label('Jenis')->badge()->color('gray')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payment_method')->label('Metode')->placeholder('—')->sortable()
                    ->formatStateUsing(fn (?InvoicePaymentMethod $state): string => $state?->getLabel() ?? '—'),
                MoneyColumn::make('amount')->label('Total')->sortable(),
                TextColumn::make('due_at')->label('Jatuh tempo')->date('j M Y')->timezone(CurrentOutlet::timezone())->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('paid_at')->label('Lunas')->date('j M Y')->timezone(CurrentOutlet::timezone())->placeholder('—')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label('Status')->badge()->sortable()
                    ->formatStateUsing(fn (SubscriptionInvoice $record): string => $record->statusLabel())
                    ->color(fn (SubscriptionInvoice $record): string => $record->statusColor()),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(InvoiceStatus::class),
                SelectFilter::make('kind')->label('Jenis')->options(InvoiceKind::class),
                SelectFilter::make('payment_method')->label('Metode')->options(InvoicePaymentMethod::class),
                DateRangeFilter::make('created_at', 'Tanggal tagihan', defaultToday: false),
            ])
            ->recordAction('detail')
            ->recordActions([
                Action::make('detail')->label('Detail')->icon(Heroicon::OutlinedEye)->iconButton()->color('gray')->tooltip('Detail tagihan')
                    ->modalHeading('Detail tagihan')->modalWidth('3xl')->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
                    ->extraModalFooterActions(fn (SubscriptionInvoice $record): array => [
                        Action::make('downloadFromRow')->label('Unduh PDF')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                            ->action(fn (): StreamedResponse => $this->download($record->id)),
                    ])
                    ->modalContent(fn (SubscriptionInvoice $record) => view('filament.shared.invoice-detail', ['invoice' => $record, 'audience' => 'owner'])),
                Action::make('pdf')->label('Unduh PDF')->icon(Heroicon::OutlinedArrowDownTray)->iconButton()->color('gray')
                    ->tooltip(fn (SubscriptionInvoice $record): string => InvoicePdf::isReceipt($record) ? 'Unduh kuitansi (PDF)' : 'Unduh tagihan (PDF)')
                    ->action(fn (SubscriptionInvoice $record): StreamedResponse => $this->download($record->id)),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedDocumentText, 'tagihan', 'Tagihan muncul setelah Anda membuat tagihan perpanjangan, upgrade, atau add-on');
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
        // Menunggu verifikasi tetap tampil walau lewat jatuh tempo (tidak kedaluwarsa selama dicek admin)
        return SubscriptionInvoice::query()->with('items')->where('status', InvoiceStatus::Pending)
            ->where(fn ($q) => $q->where('due_at', '>', now())->orWhereNotNull('proof_submitted_at'))
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
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Buat tagihan')
            ->modalDescription('Upgrade ke paket lebih mahal: bayar selisih sisa masa aktif, paket langsung berganti. Lainnya: perpanjangan dari akhir masa aktif sekarang')
            ->fillForm(fn (): array => [
                'plan_id' => $this->tenant()->plan_id ?? $this->plans()->first()?->id,
                'billing_cycle' => ($this->tenant()->billing_cycle ?? BillingCycle::Monthly)->value,
            ])
            ->schema([
                ToggleButtons::make('billing_cycle')->label('Siklus tagihan')->required()->inline()->options(BillingCycle::class)->live(),
                Radio::make('plan_id')->label('Paket')->required()->live()
                    ->options(fn (): array => $this->plans()->mapWithKeys(fn (Plan $p): array => [$p->id => $p->name.($p->id === $this->tenant()->plan_id ? ' (paket saat ini)' : '')])->all())
                    ->descriptions(fn (Get $get): array => $this->plans()->mapWithKeys(fn (Plan $p): array => [$p->id => $this->planPriceText($p, $get('billing_cycle')).($p->limits ? ' · '.$p->limitsSummary() : '')])->all())
                    ->extraAttributes(['class' => 'gs-choice-cards']),
                Placeholder::make('quote')->label('Ringkasan tagihan')
                    ->content(fn (Get $get): HtmlString => $this->quoteHtml($get('plan_id'), $get('billing_cycle'))),
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

    /** Ringkasan tagihan bergaya kartu: total besar, jenis & efek (InvoiceQuote, sama dengan server). */
    public function quoteHtml(mixed $planId, mixed $cycle): HtmlString
    {
        $plan = $this->plans()->firstWhere('id', $planId);
        $cycle = self::cycle($cycle);
        $quote = $plan !== null && $cycle !== null ? InvoiceQuote::for($this->tenant(), $plan, $cycle) : null;

        return new HtmlString(view('filament.dashboard.subscription.quote', [
            'quote' => $quote,
            'text' => $quote !== null ? $quote->summary($plan, CurrentOutlet::timezone()) : 'Pilih paket & siklus',
        ])->render());
    }

    private function planPriceText(Plan $plan, mixed $cycle): string
    {
        return self::cycle($cycle) === BillingCycle::Yearly
            ? Money::format($plan->price_yearly).'/tahun'
            : Money::format($plan->price_monthly).'/bulan';
    }

    private static function cycle(mixed $value): ?BillingCycle
    {
        return $value instanceof BillingCycle ? $value : BillingCycle::tryFrom((string) $value);
    }

    /** @return list<InvoicePaymentMethod> metode yang tersedia sekarang (QRIS & VA butuh Midtrans vendor) */
    public function paymentMethods(): array
    {
        return array_values(array_filter(InvoicePaymentMethod::cases(), fn (InvoicePaymentMethod $m): bool => $m->isOnline()
            ? $this->onlinePaymentAvailable()
            : app(SystemSettings::class)->manualBillingAvailable() || ! $this->onlinePaymentAvailable()));
    }

    /** Bayar tagihan menunggu (argumen id): pilih QRIS / VA (Snap Midtrans) atau transfer manual + unggah bukti. */
    public function payInvoiceAction(): Action
    {
        return Action::make('payInvoice')->label('Bayar sekarang')->icon(Heroicon::OutlinedCreditCard)
            ->modalHeading(fn (array $arguments): string => 'Bayar tagihan '.($this->payableInvoice($arguments)->number ?? ''))
            ->modalDescription('Pilih cara bayar. QRIS & VA lunas otomatis; transfer manual diverifikasi tim gs.POS')
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Lanjutkan')
            ->fillForm(fn (array $arguments): array => [
                'method' => ($this->payableInvoice($arguments)->payment_method ?? $this->paymentMethods()[0] ?? InvoicePaymentMethod::Manual)->value,
            ])
            ->schema(fn (array $arguments): array => [
                Placeholder::make('summary')->hiddenLabel()
                    ->content(fn (): HtmlString => new HtmlString(view('filament.dashboard.subscription.pay-summary', ['invoice' => $this->payableInvoice($arguments)])->render())),
                Radio::make('method')->label('Metode pembayaran')->required()->live()
                    ->options(collect($this->paymentMethods())->mapWithKeys(fn (InvoicePaymentMethod $m): array => [$m->value => $m->getLabel()])->all())
                    ->descriptions(collect($this->paymentMethods())->mapWithKeys(fn (InvoicePaymentMethod $m): array => [$m->value => $m->getDescription()])->all())
                    ->extraAttributes(['class' => 'gs-choice-cards']),
                Placeholder::make('transfer_to')->label('Transfer ke')
                    ->visible(fn (Get $get): bool => $get('method') === InvoicePaymentMethod::Manual->value)
                    ->content(fn (): HtmlString => new HtmlString(view('filament.dashboard.subscription.bank-accounts', [
                        'accounts' => app(SystemSettings::class)->billingBankAccounts(),
                        'qris' => app(SystemSettings::class)->billingQrisImage(),
                        'note' => app(SystemSettings::class)->billingManualNote(),
                        'invoice' => $this->payableInvoice($arguments),
                    ])->render())),
                FileUpload::make('proof')->label('Bukti transfer')->required()
                    ->visible(fn (Get $get): bool => $get('method') === InvoicePaymentMethod::Manual->value)
                    ->disk('local')->directory(fn (): string => SubmitPaymentProof::directory(TenantContext::idOrFail()))->visibility('private')
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'application/pdf'])->maxSize(2048)
                    ->helperText('Foto/screenshot atau PDF, maks. 2 MB. Pastikan nominal, tanggal & rekening tujuan terlihat'),
                TextInput::make('proof_sender')->label('Nama pengirim & bank asal')->required()->maxLength(120)
                    ->placeholder('mis. Budi Santoso · BCA')
                    ->visible(fn (Get $get): bool => $get('method') === InvoicePaymentMethod::Manual->value),
                Textarea::make('proof_note')->label('Catatan (opsional)')->rows(2)->maxLength(500)
                    ->visible(fn (Get $get): bool => $get('method') === InvoicePaymentMethod::Manual->value),
            ])
            // Argumen id: tagihan menunggu milik bisnis ini (scope tenant)
            ->action(function (Action $action, array $data, array $arguments): void {
                abort_unless(self::canAccess(), 403);
                $user = auth()->user();
                abort_unless($user instanceof User, 403);
                $invoice = $this->payableInvoice($arguments) ?? abort(404);
                $method = InvoicePaymentMethod::tryFrom((string) ($data['method'] ?? ''));
                abort_unless($method !== null && in_array($method, $this->paymentMethods(), true), 422);

                try {
                    if ($method->isOnline()) {
                        $action->redirect(app(StartInvoicePayment::class)->handle($invoice, $user, self::getUrl(), $method));

                        return;
                    }

                    app(SubmitPaymentProof::class)->handle($invoice, $user, (string) $data['proof'], (string) ($data['proof_sender'] ?? ''), $data['proof_note'] ?? null);
                } catch (BusinessException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('Bukti transfer terkirim')
                    ->body('Tim gs.POS memverifikasi maks. 1×24 jam kerja. Status tagihan berubah otomatis setelah diverifikasi')->send();
            });
    }

    /** Detail tagihan (argumen id): rincian baris, total, pembayaran & bukti transfer. */
    public function invoiceDetailAction(): Action
    {
        return Action::make('invoiceDetail')->label('Detail')->icon(Heroicon::OutlinedEye)->iconButton()->color('gray')->tooltip('Detail tagihan')
            ->modalHeading('Detail tagihan')
            ->modalWidth('3xl')->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
            ->extraModalFooterActions(fn (array $arguments): array => [
                Action::make('downloadFromDetail')->label('Unduh PDF')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->action(fn (): StreamedResponse => $this->download((string) ($arguments['id'] ?? ''))),
            ])
            ->modalContent(function (array $arguments) {
                abort_unless(self::canAccess(), 403);
                // Scope tenant: tagihan bisnis lain = 404
                $invoice = SubscriptionInvoice::query()->with('items')->findOrFail((string) ($arguments['id'] ?? ''));

                return view('filament.shared.invoice-detail', ['invoice' => $invoice, 'audience' => 'owner']);
            });
    }

    /** Unduh PDF (argumen id): tagihan, atau kuitansi bila sudah lunas. Scope tenant: tagihan bisnis lain = 404. */
    public function downloadPdfAction(): Action
    {
        return Action::make('downloadPdf')->label('Unduh PDF')->icon(Heroicon::OutlinedArrowDownTray)->iconButton()->color('gray')
            ->tooltip(fn (array $arguments): string => SubscriptionInvoice::query()->whereKey((string) ($arguments['id'] ?? ''))->value('status') === InvoiceStatus::Paid->value ? 'Unduh kuitansi (PDF)' : 'Unduh tagihan (PDF)')
            ->action(fn (array $arguments): StreamedResponse => $this->download((string) ($arguments['id'] ?? '')));
    }

    private function download(string $id): StreamedResponse
    {
        abort_unless(self::canAccess(), 403);

        return app(InvoicePdf::class)->download(SubscriptionInvoice::query()->with('items')->findOrFail($id));
    }

    /** @param  array<string, mixed>  $arguments */
    private function payableInvoice(array $arguments): ?SubscriptionInvoice
    {
        return $this->pendingInvoices()->first(fn (SubscriptionInvoice $i): bool => $i->id === (string) ($arguments['id'] ?? '') && $i->isPayable());
    }
}
