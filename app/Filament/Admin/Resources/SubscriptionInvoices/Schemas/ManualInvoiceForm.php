<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SubscriptionInvoices\Schemas;

use App\Actions\Billing\CreateManualInvoice;
use App\Enums\BillingCycle;
use App\Filament\Shared\Forms\MoneyInput;
use App\Filament\Shared\Forms\MoneyStateCast;
use App\Models\Addon;
use App\Models\Tenant;
use App\Services\Billing\ManualInvoiceTotals;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Form tagihan manual admin (CreateManualInvoice): pilih bisnis, baris biaya bebas / add-on harga khusus, diskon,
 * PPN, jatuh tempo, catatan untuk pelanggan. Pratinjau total memakai ManualInvoiceTotals (sama dengan server).
 */
final class ManualInvoiceForm
{
    /** Nilai pilihan "Item" untuk biaya bebas; selain ini = id add-on. */
    public const CUSTOM = 'custom';

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Pelanggan & tagihan')->icon(Heroicon::OutlinedBuildingStorefront)->description('Tagihan tidak mengubah paket & masa aktif. Tagihan menunggu lain tidak dibatalkan')
                ->columns(2)->columnSpan(['default' => 3, 'lg' => 2])->schema([
                    Select::make('tenant_id')->label('Bisnis')->required()->searchable()->live()
                        ->getSearchResultsUsing(fn (string $search): array => Tenant::query()->where('name', 'like', '%'.$search.'%')
                            ->orderBy('name')->limit(30)->pluck('name', 'id')->all())
                        ->options(fn (): array => Tenant::query()->latest()->limit(30)->pluck('name', 'id')->all())
                        ->getOptionLabelUsing(fn (mixed $value): ?string => Tenant::query()->whereKey($value)->value('name'))
                        ->helperText(fn (Get $get): ?string => self::tenantInfo($get('tenant_id'))),
                    TextInput::make('title')->label('Judul tagihan')->required()->maxLength(120)
                        ->placeholder('mis. Biaya instalasi & pelatihan'),
                    DatePicker::make('due_at')->label('Jatuh tempo')->required()->minDate(fn (): string => CreateManualInvoice::today())
                        ->default(fn (): string => CarbonImmutable::parse(CreateManualInvoice::today())->addDays((int) config('pos.billing.due_days'))->toDateString()),
                    Toggle::make('notify_owners')->label('Kirim email tagihan ke owner')->default(true)->inline(false),
                ]),
            Section::make('Total')->icon(Heroicon::OutlinedCalculator)->description('Dihitung ulang di server saat disimpan')
                ->columnSpan(['default' => 3, 'lg' => 1])->extraAttributes(['class' => 'gs-summary'])->schema([
                    Placeholder::make('summary')->hiddenLabel()
                        ->content(fn (Get $get): HtmlString => self::summaryHtml(self::totals($get))),
                ]),
            Section::make('Rincian')->icon(Heroicon::OutlinedListBullet)->description('Baris add-on dipasang ke bisnis saat tagihan lunas; biaya lain tidak mengubah apa pun')
                ->columnSpanFull()
                ->headerActions([
                    Action::make('addLine')->label('Tambah baris')->icon(Heroicon::OutlinedPlus)
                        ->disabled(fn (Get $get): bool => count($get('items') ?? []) >= CreateManualInvoice::MAX_LINES)
                        ->action(fn (Get $get, Set $set) => $set('items', [
                            ...($get('items') ?? []),
                            (string) Str::uuid() => self::emptyLine(),
                        ])),
                ])
                ->schema([
                    Repeater::make('items')->hiddenLabel()
                        ->table([
                            TableColumn::make('Item')->width('220px')->markAsRequired(),
                            TableColumn::make('Uraian')->markAsRequired(),
                            TableColumn::make('Jumlah')->width('96px')->markAsRequired(),
                            TableColumn::make('Harga satuan')->width('170px')->markAsRequired(),
                            TableColumn::make('Subtotal')->width('140px')->alignEnd(),
                        ])
                        ->compact()
                        ->schema([
                            Select::make('source')->label('Item')->hiddenLabel()->required()->live()->selectablePlaceholder(false)
                                ->options(fn (): array => self::sourceOptions())
                                ->afterStateUpdated(fn (mixed $state, Get $get, Set $set) => self::fillFromAddon($state, $get, $set)),
                            TextInput::make('name')->label('Uraian')->hiddenLabel()->required()->maxLength(120)->placeholder('mis. Instalasi & pelatihan 1 hari'),
                            TextInput::make('quantity')->label('Jumlah')->hiddenLabel()->required()->integer()->minValue(1)->maxValue(999)->default(1)
                                ->live(onBlur: true),
                            MoneyInput::make('unit_price')->label('Harga satuan')->hiddenLabel()->required()->rule('max:'.Money::MAX_PRICE)->live(onBlur: true),
                            Placeholder::make('line_total')->label('Subtotal')->hiddenLabel()
                                ->content(fn (Get $get): string => Money::format(Money::mul(self::money($get('unit_price')), max(0, (int) $get('quantity')))))
                                ->extraAttributes(['class' => 'text-right tabular-nums']),
                        ])
                        ->minItems(1)
                        ->maxItems(CreateManualInvoice::MAX_LINES)
                        ->defaultItems(1)
                        ->default(fn (): array => [(string) Str::uuid() => self::emptyLine()])
                        ->reorderableWithButtons()
                        ->reorderableWithDragAndDrop(false)
                        ->addable(false)
                        ->deleteAction(fn (Action $action): Action => $action->tooltip('Hapus baris')),
                ]),
            Section::make('Diskon & pajak')->icon(Heroicon::OutlinedReceiptPercent)->description('Diskon nominal dari subtotal; PPN dihitung dari subtotal setelah diskon')
                ->columns(2)->columnSpan(['default' => 3, 'lg' => 1])->schema([
                    MoneyInput::make('discount_amount')->label('Diskon')->live(onBlur: true),
                    TextInput::make('tax_rate')->label('PPN')->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%')
                        ->default(0)->live(onBlur: true)->helperText('0 = tanpa PPN'),
                ]),
            Section::make('Catatan untuk pelanggan')->icon(Heroicon::OutlinedChatBubbleBottomCenterText)->description('Tampil di halaman Langganan owner & email tagihan')
                ->columnSpan(['default' => 3, 'lg' => 2])->schema([
                    Textarea::make('memo')->hiddenLabel()->maxLength(1000)->rows(3)->autosize()
                        ->placeholder('mis. Pembayaran ke BCA 123 456 7890 a.n. PT Abati. Cantumkan nomor tagihan.'),
                ]),
        ]);
    }

    /**
     * Data form → data CreateManualInvoice (pilihan "Item" dipecah jadi type + addon_id; uang dinormalkan).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toActionData(array $data): array
    {
        $items = [];
        foreach ((array) ($data['items'] ?? []) as $item) {
            $source = (string) ($item['source'] ?? self::CUSTOM);
            $items[] = [
                'type' => $source === self::CUSTOM ? 'custom' : 'addon',
                'addon_id' => $source === self::CUSTOM ? null : $source,
                'name' => $item['name'] ?? null,
                'quantity' => $item['quantity'] ?? null,
                'unit_price' => self::money($item['unit_price'] ?? null),
            ];
        }

        return [
            'title' => $data['title'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'items' => $items,
            'discount_amount' => filled($data['discount_amount'] ?? null) ? self::money($data['discount_amount']) : null,
            'tax_rate' => $data['tax_rate'] ?? null,
            'memo' => $data['memo'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private static function emptyLine(): array
    {
        return ['source' => self::CUSTOM, 'name' => null, 'quantity' => 1, 'unit_price' => null];
    }

    /** @return array<string, array<string, string>> */
    private static function sourceOptions(): array
    {
        $addons = Addon::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->mapWithKeys(fn (Addon $a): array => [$a->id => $a->name.($a->is_public ? '' : ' (khusus)')])->all();

        return ['Biaya' => [self::CUSTOM => 'Biaya lain (isi bebas)'], 'Add-on' => $addons];
    }

    /** Pilih add-on: uraian & harga diisi dari add-on (siklus bisnis; tanpa siklus = bulanan), tetap bisa diubah. */
    private static function fillFromAddon(mixed $state, Get $get, Set $set): void
    {
        $addon = $state !== self::CUSTOM && filled($state) ? Addon::query()->find($state) : null;
        if ($addon === null) {
            return;
        }

        $tenant = filled($get('../../tenant_id')) ? Tenant::query()->find($get('../../tenant_id')) : null;
        $cycle = $tenant->billing_cycle ?? BillingCycle::Monthly;
        $set('name', $addon->name.' ('.$cycle->getLabel().')');
        $set('unit_price', (new MoneyStateCast)->set($addon->price($cycle)));
        if (! $addon->isQuota()) {
            $set('quantity', 1);
        }
    }

    private static function totals(Get $get): ManualInvoiceTotals
    {
        $data = self::toActionData([
            'items' => $get('items') ?? [],
            'discount_amount' => $get('discount_amount'),
            'tax_rate' => $get('tax_rate'),
        ]);

        return ManualInvoiceTotals::of($data['items'], $data['discount_amount'], $data['tax_rate']);
    }

    private static function summaryHtml(ManualInvoiceTotals $t): HtmlString
    {
        $row = fn (string $label, string $value, string $class = ''): string => '<div class="gs-invoice-sum-row '.$class.'"><span>'.e($label).'</span><span class="tabular-nums">'.e($value).'</span></div>';

        return new HtmlString('<div class="gs-invoice-sum">'
            .$row('Subtotal', Money::format($t->subtotal))
            .$row('Diskon', bccomp($t->discount, '0', 2) > 0 ? '−'.Money::format($t->discount) : '—')
            .$row('PPN'.(bccomp($t->taxRate, '0', 2) > 0 ? ' '.rtrim(rtrim($t->taxRate, '0'), '.').'%' : ''), bccomp($t->tax, '0', 2) > 0 ? Money::format($t->tax) : '—')
            .$row('Total', Money::format($t->total), 'is-total')
            .'</div>');
    }

    private static function tenantInfo(mixed $tenantId): ?string
    {
        $tenant = filled($tenantId) ? Tenant::query()->with('plan')->find($tenantId) : null;
        if ($tenant === null) {
            return null;
        }

        $ends = $tenant->subscription_ends_at?->setTimezone((string) config('pos.default_timezone'))->locale('id')->translatedFormat('j M Y');

        return ($tenant->plan->name ?? 'Tanpa paket').' · '.($tenant->billing_cycle?->getLabel() ?? '—').' · '.$tenant->status->getLabel()
            .($ends !== null ? ' s/d '.$ends : '');
    }

    private static function money(mixed $value): string
    {
        $value = (new MoneyStateCast)->get($value);

        // Hanya angka desimal biasa: notasi ilmiah ("1e5") membuat bcmath gagal di pratinjau
        return (is_string($value) || is_int($value)) && preg_match('/^\d+(\.\d+)?$/', (string) $value) === 1 ? (string) $value : '0';
    }
}
