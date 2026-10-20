<?php

declare(strict_types=1);

use App\Actions\Billing\CreateAddonInvoice;
use App\Actions\Billing\CreateInvoice;
use App\Actions\Billing\CreateManualInvoice;
use App\Actions\Billing\MarkInvoicePaid;
use App\Actions\Billing\SaveAddon;
use App\Actions\Billing\SavePlan;
use App\Enums\BillingCycle;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\TenantStatus;
use App\Exceptions\BusinessException;
use App\Filament\Admin\Resources\SubscriptionInvoices\Pages\CreateSubscriptionInvoice;
use App\Filament\Admin\Resources\SubscriptionInvoices\Pages\ListSubscriptionInvoices;
use App\Filament\Dashboard\Pages\SubscriptionSettings;
use App\Models\Admin;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\User;
use App\Notifications\InvoiceCreated;
use App\Services\Billing\ManualInvoiceTotals;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Tagihan manual admin (2026-10-19): biaya bebas + add-on harga khusus, diskon, PPN, jatuh tempo, catatan. Tidak
 * mengubah paket/masa aktif, tidak membatalkan tagihan lain; baris add-on dipasang saat lunas.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-16 03:00:00');
    $this->pos = posSetup(openShift: false);
    $this->tenant = Tenant::query()->findOrFail($this->pos->tenantId);
    $this->admin = Admin::factory()->withTwoFactor()->create();
    $this->basic = app(SavePlan::class)->handle(null, ['code' => 'basic', 'name' => 'Basic', 'price_monthly' => '99000', 'price_yearly' => '990000', 'features' => ['member']]);
    $this->extraOutlet = app(SaveAddon::class)->handle(null, ['code' => 'extra-outlet', 'name' => 'Outlet tambahan', 'type' => 'quota', 'quota_key' => 'outlets', 'quota_amount' => 1, 'price_monthly' => '99000', 'price_yearly' => '990000']);
    $this->memberAddon = app(SaveAddon::class)->handle(null, ['code' => 'addon-member', 'name' => 'Program member', 'type' => 'feature', 'feature_key' => 'member', 'price_monthly' => '49000', 'price_yearly' => '490000']);
    $this->tenant->forceFill(['status' => TenantStatus::Active, 'plan_id' => $this->basic->id, 'billing_cycle' => BillingCycle::Monthly, 'subscription_ends_at' => now()->addDays(15)])->save();
});

afterEach(fn () => Carbon::setTestNow());

/** @return array<string, mixed> */
function manualInvoiceData(array $override = []): array
{
    return [
        'title' => 'Instalasi & outlet tambahan',
        'due_at' => '2026-10-23',
        'items' => [
            ['type' => 'custom', 'addon_id' => null, 'name' => 'Instalasi & pelatihan', 'quantity' => 1, 'unit_price' => '750000'],
            ['type' => 'addon', 'addon_id' => test()->extraOutlet->id, 'name' => 'Outlet tambahan (harga khusus)', 'quantity' => 2, 'unit_price' => '80000'],
        ],
        'discount_amount' => '10000',
        'tax_rate' => '11',
        'memo' => 'Transfer ke BCA 123',
        ...$override,
    ];
}

describe('ManualInvoiceTotals', function () {
    it('subtotal, diskon, PPN dibulatkan ke rupiah, total', function () {
        $t = ManualInvoiceTotals::of([
            ['type' => 'custom', 'name' => 'A', 'quantity' => 3, 'unit_price' => '33333'],
        ], '0', '11');

        // 99.999 × 11% = 10.999,89 → 11.000
        expect($t->subtotal)->toBe('99999.00')->and($t->tax)->toBe('11000.00')->and($t->total)->toBe('110999.00');
    });
});

describe('CreateManualInvoice', function () {
    it('membuat tagihan manual dengan baris, diskon, PPN, catatan; paket & masa aktif tetap', function () {
        $endsBefore = $this->tenant->subscription_ends_at;
        $invoice = app(CreateManualInvoice::class)->handle($this->tenant, $this->admin, manualInvoiceData());

        // 750.000 + 160.000 = 910.000 − 10.000 = 900.000 + PPN 99.000 = 999.000
        expect($invoice->kind)->toBe(InvoiceKind::Manual)
            ->and($invoice->title)->toBe('Instalasi & outlet tambahan')
            ->and($invoice->amount)->toBe('999000.00')
            ->and($invoice->discount_amount)->toBe('10000.00')
            ->and($invoice->tax_amount)->toBe('99000.00')
            ->and($invoice->memo)->toBe('Transfer ke BCA 123')
            ->and($invoice->created_by_admin_id)->toBe($this->admin->id)
            ->and($invoice->due_at->toIso8601String())->toBe('2026-10-23T16:59:59+00:00')
            ->and($invoice->items->map(fn ($i) => [$i->type, $i->name, $i->quantity, $i->amount])->all())->toBe([
                ['custom', 'Instalasi & pelatihan', 1, '750000.00'],
                ['addon', 'Outlet tambahan (harga khusus)', 2, '160000.00'],
            ])
            ->and($invoice->description())->toBe('Instalasi & outlet tambahan · 2 item')
            ->and($this->tenant->refresh()->subscription_ends_at->equalTo($endsBefore))->toBeTrue();
    });

    it('tidak membatalkan tagihan menunggu lain, dan tagihan paket baru tidak membatalkan tagihan manual', function () {
        $renewal = TenantContext::run($this->tenant->id, fn () => app(CreateInvoice::class)->handle($this->tenant->refresh(), $this->basic, BillingCycle::Monthly));
        $manual = app(CreateManualInvoice::class)->handle($this->tenant, $this->admin, manualInvoiceData());
        $renewal2 = TenantContext::run($this->tenant->id, fn () => app(CreateInvoice::class)->handle($this->tenant->refresh(), $this->basic, BillingCycle::Monthly));

        expect($manual->refresh()->status)->toBe(InvoiceStatus::Pending)
            ->and($renewal->refresh()->status)->toBe(InvoiceStatus::Cancelled)
            ->and($renewal2->status)->toBe(InvoiceStatus::Pending);
    });

    it('lunas: baris add-on dipasang, biaya bebas tidak mengubah apa pun; lunas ulang tidak menambah unit', function () {
        $invoice = app(CreateManualInvoice::class)->handle($this->tenant, $this->admin, manualInvoiceData());

        app(MarkInvoicePaid::class)->handle($invoice, 'manual', $this->admin, 'BCA');
        app(MarkInvoicePaid::class)->handle($invoice, 'manual', $this->admin, 'BCA');

        $row = TenantAddon::forTenant($this->tenant->id)->where('addon_id', $this->extraOutlet->id)->sole();
        expect($row->quantity)->toBe(2)
            ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($invoice->period_start)->toBeNull()
            ->and($this->tenant->refresh()->plan_id)->toBe($this->basic->id);
    });

    it('bisnis tanpa paket tetap bisa ditagih (plan kosong)', function () {
        $this->tenant->forceFill(['plan_id' => null, 'billing_cycle' => null, 'status' => TenantStatus::Trial])->save();

        $invoice = app(CreateManualInvoice::class)->handle($this->tenant->refresh(), $this->admin, manualInvoiceData(['items' => [
            ['type' => 'custom', 'name' => 'Perangkat printer', 'quantity' => 1, 'unit_price' => '1200000'],
        ], 'discount_amount' => null, 'tax_rate' => null]));

        app(MarkInvoicePaid::class)->handle($invoice, 'manual', $this->admin);

        expect($invoice->refresh()->plan_id)->toBeNull()->and($invoice->amount)->toBe('1200000.00')
            ->and($invoice->status)->toBe(InvoiceStatus::Paid)
            ->and($this->tenant->refresh()->status)->toBe(TenantStatus::Trial);
    });

    it('email ke owner bila diminta, tanpa kalimat "tagihan sebelumnya dibatalkan"', function () {
        Notification::fake();
        $owner = User::factory()->owner()->create(['tenant_id' => $this->tenant->id, 'email' => 'owner@kopi.test']);

        app(CreateManualInvoice::class)->handle($this->tenant, $this->admin, manualInvoiceData(), notifyOwners: true);

        Notification::assertSentTo($owner, InvoiceCreated::class, function (InvoiceCreated $n) use ($owner): bool {
            $mail = $n->toMail($owner);

            return $n->manual && ! str_contains(implode(' ', $mail->outroLines), 'dibatalkan') && in_array('Transfer ke BCA 123', $mail->introLines, true);
        });
    });

    it('validasi: judul, rincian kosong, jatuh tempo lewat, jumlah, harga', function (array $override, string $field) {
        expect(fn () => app(CreateManualInvoice::class)->handle($this->tenant, $this->admin, manualInvoiceData($override)))
            ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey($field));

        expect(SubscriptionInvoice::allTenants()->count())->toBe(0);
    })->with([
        'judul kosong' => [['title' => ''], 'title'],
        'tanpa baris' => [['items' => []], 'items'],
        'jatuh tempo lewat' => [['due_at' => '2026-10-01'], 'due_at'],
        'jumlah 0' => [['items' => [['type' => 'custom', 'name' => 'A', 'quantity' => 0, 'unit_price' => '1000']]], 'items.0.quantity'],
        'harga negatif' => [['items' => [['type' => 'custom', 'name' => 'A', 'quantity' => 1, 'unit_price' => '-5']]], 'items.0.unit_price'],
        'add-on tidak dikenal' => [['items' => [['type' => 'addon', 'addon_id' => (string) Str::uuid(), 'name' => 'X', 'quantity' => 1, 'unit_price' => '1']]], 'items.0.addon_id'],
        'PPN > 100' => [['tax_rate' => '150'], 'tax_rate'],
    ]);

    it('aturan bisnis: diskon > subtotal, total 0, add-on fitur sudah termasuk paket / lebih dari 1 unit', function (string $case, string $message) {
        $memberLine = fn (int $qty): array => [['type' => 'addon', 'addon_id' => $this->memberAddon->id, 'name' => 'Member', 'quantity' => $qty, 'unit_price' => '1000']];
        $override = match ($case) {
            'diskon' => ['discount_amount' => '99999999'],
            'total0' => ['items' => [['type' => 'custom', 'name' => 'Gratis', 'quantity' => 1, 'unit_price' => '0']], 'discount_amount' => null],
            'fitur_di_paket' => ['items' => $memberLine(1), 'discount_amount' => null],
            'fitur_2_unit' => ['items' => $memberLine(2), 'discount_amount' => null],
        };

        expect(fn () => app(CreateManualInvoice::class)->handle($this->tenant, $this->admin, manualInvoiceData($override)))
            ->toThrow(BusinessException::class, $message);
    })->with([
        ['diskon', 'Diskon melebihi subtotal'],
        ['total0', 'Total tagihan minimal Rp1'],
        ['fitur_di_paket', 'sudah termasuk paket'],
        ['fitur_2_unit', 'hanya 1 unit'],
    ]);
});

describe('panel /admin', function () {
    beforeEach(function () {
        TenantContext::forget();
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin, 'admin');
    });

    it('halaman Tambah membuat tagihan manual lewat form (pilihan add-on → baris add-on)', function () {
        Livewire::test(CreateSubscriptionInvoice::class)
            ->fillForm([
                'tenant_id' => $this->tenant->id,
                'title' => 'Biaya instalasi',
                'due_at' => '2026-10-20',
                'notify_owners' => false,
                'items' => [
                    ['source' => 'custom', 'name' => 'Instalasi', 'quantity' => 1, 'unit_price' => '500.000'],
                    ['source' => $this->extraOutlet->id, 'name' => 'Outlet tambahan', 'quantity' => 1, 'unit_price' => '99.000'],
                ],
                'discount_amount' => null,
                'tax_rate' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $invoice = SubscriptionInvoice::allTenants()->with('items')->sole();
        expect($invoice->kind)->toBe(InvoiceKind::Manual)->and($invoice->amount)->toBe('599000.00')
            ->and($invoice->items->pluck('type')->all())->toBe(['custom', 'addon']);
    });

    it('daftar tagihan: tab & filter jenis/bisnis/total', function () {
        $manual = app(CreateManualInvoice::class)->handle($this->tenant, $this->admin, manualInvoiceData());
        $renewal = TenantContext::run($this->tenant->id, fn () => app(CreateInvoice::class)->handle($this->tenant->refresh(), $this->basic, BillingCycle::Monthly));
        TenantContext::forget();

        Livewire::test(ListSubscriptionInvoices::class)
            ->assertCanSeeTableRecords([$manual, $renewal])
            ->filterTable('kind', InvoiceKind::Manual->value)
            ->assertCanSeeTableRecords([$manual])->assertCanNotSeeTableRecords([$renewal])
            ->resetTableFilters()
            ->filterTable('amount', ['min' => '500.000'])
            ->assertCanSeeTableRecords([$manual])->assertCanNotSeeTableRecords([$renewal]);
    });

    it('akun tenant tidak bisa membuka halaman tagihan manual admin', function () {
        auth('admin')->logout();
        $this->actingAs(User::factory()->owner()->create());

        expect($this->get('/admin/invoices/create')->status())->toBeIn([302, 403]);
    });
});

describe('owner (Pengaturan → Langganan)', function () {
    it('tagihan manual tampil di tagihan menunggu bersama tagihan paket; tenant lain tidak terlihat', function () {
        $manual = app(CreateManualInvoice::class)->handle($this->tenant, $this->admin, manualInvoiceData());
        $other = posSetup(openShift: false);
        $foreign = app(CreateManualInvoice::class)->handle(Tenant::query()->findOrFail($other->tenantId), $this->admin, manualInvoiceData(['items' => [
            ['type' => 'custom', 'name' => 'Lain', 'quantity' => 1, 'unit_price' => '1000'],
        ], 'discount_amount' => null]));

        $owner = User::factory()->owner()->create(['tenant_id' => $this->tenant->id]);
        freshAuth();
        $this->actingAs($owner);
        Filament::setCurrentPanel('dashboard');
        TenantContext::set($this->tenant->id);

        $page = Livewire::test(SubscriptionSettings::class)->assertSee($manual->number)->assertDontSee($foreign->number);
        expect($page->instance()->pendingInvoices()->pluck('id')->all())->toBe([$manual->id]);
    });
});

describe('perbaikan review 2026-10-19', function () {
    it('add-on fitur tidak bisa ditagih ganda: dua baris, dua tagihan manual, atau tagihan add-on owner', function () {
        $selfOrder = app(SaveAddon::class)->handle(null, ['code' => 'self-order', 'name' => 'Self-order QR', 'type' => 'feature', 'feature_key' => 'self_order', 'price_monthly' => '50000', 'price_yearly' => '500000']);
        $line = fn (): array => ['type' => 'addon', 'addon_id' => $selfOrder->id, 'name' => 'Self-order', 'quantity' => 1, 'unit_price' => '50000'];

        expect(fn () => app(CreateManualInvoice::class)->handle($this->tenant, $this->admin, manualInvoiceData(['items' => [$line(), $line()], 'discount_amount' => null])))
            ->toThrow(BusinessException::class, 'sudah ada di baris lain');

        app(CreateManualInvoice::class)->handle($this->tenant, $this->admin, manualInvoiceData(['items' => [$line()], 'discount_amount' => null]));

        expect(fn () => app(CreateManualInvoice::class)->handle($this->tenant, $this->admin, manualInvoiceData(['items' => [$line()], 'discount_amount' => null])))
            ->toThrow(BusinessException::class, 'sudah ada di tagihan menunggu lain')
            ->and(fn () => TenantContext::run($this->tenant->id, fn () => app(CreateAddonInvoice::class)->handle($this->tenant->refresh(), $selfOrder, 1)))
            ->toThrow(BusinessException::class, 'sudah ada di tagihan menunggu');
    });

    it('add-on kuota boleh di dua baris (unit dijumlah saat lunas)', function () {
        $line = fn (int $q): array => ['type' => 'addon', 'addon_id' => $this->extraOutlet->id, 'name' => 'Outlet', 'quantity' => $q, 'unit_price' => '50000'];
        $invoice = app(CreateManualInvoice::class)->handle($this->tenant, $this->admin, manualInvoiceData(['items' => [$line(1), $line(2)], 'discount_amount' => null]));

        app(MarkInvoicePaid::class)->handle($invoice, 'manual', $this->admin);

        expect(TenantAddon::forTenant($this->tenant->id)->where('addon_id', $this->extraOutlet->id)->value('quantity'))->toBe(3);
    });

    it('jatuh tempo memakai tanggal WIB: 01.00 WIB tanggal 17, "kemarin" (16) ditolak', function () {
        $this->travelTo(Carbon::parse('2026-10-16 18:00:00')); // 17 Okt 01.00 WIB

        expect(fn () => app(CreateManualInvoice::class)->handle($this->tenant, $this->admin, manualInvoiceData(['due_at' => '2026-10-16'])))
            ->toThrow(ValidationException::class);

        $invoice = app(CreateManualInvoice::class)->handle($this->tenant, $this->admin, manualInvoiceData(['due_at' => '2026-10-17']));
        expect($invoice->due_at->isFuture())->toBeTrue();
    });

    it('nominal ber-sen & notasi ilmiah ditolak (Midtrans hanya rupiah bulat, bcmath tidak menerima 1e5)', function (array $override, string $field) {
        expect(fn () => app(CreateManualInvoice::class)->handle($this->tenant, $this->admin, manualInvoiceData($override)))
            ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey($field));
    })->with([
        'harga ber-sen' => [['items' => [['type' => 'custom', 'name' => 'A', 'quantity' => 1, 'unit_price' => '1000.50']]], 'items.0.unit_price'],
        'harga 1e5' => [['items' => [['type' => 'custom', 'name' => 'A', 'quantity' => 1, 'unit_price' => '1e5']]], 'items.0.unit_price'],
        'diskon ber-sen' => [['discount_amount' => '10.5'], 'discount_amount'],
        'PPN 1e1' => [['tax_rate' => '1e1'], 'tax_rate'],
    ]);

    it('pratinjau aman untuk input bukan angka biasa', function () {
        $t = ManualInvoiceTotals::of([['type' => 'custom', 'name' => 'A', 'quantity' => 1, 'unit_price' => '1e5']], '1e2', '1e1');

        expect($t->total)->toBe('0.00')->and($t->taxRate)->toBe('0.00');
    });

    it('Tandai lunas hanya untuk tagihan menunggu / kedaluwarsa, tidak untuk dibatalkan', function () {
        TenantContext::forget();
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin, 'admin');
        $invoice = app(CreateManualInvoice::class)->handle($this->tenant, $this->admin, manualInvoiceData());
        $invoice->forceFill(['status' => InvoiceStatus::Cancelled])->save();

        Livewire::test(ListSubscriptionInvoices::class)
            ->assertActionHidden(TestAction::make('markPaid')->table($invoice));

        $invoice->forceFill(['status' => InvoiceStatus::Expired])->save();
        Livewire::test(ListSubscriptionInvoices::class)
            ->assertActionVisible(TestAction::make('markPaid')->table($invoice));
    });

    it('owner tidak bisa membayar tagihan bisnis lain lewat argumen id', function () {
        config(['pos.billing.midtrans_server_key' => 'SB-vendor-key']);
        $other = posSetup(openShift: false);
        $foreign = app(CreateManualInvoice::class)->handle(Tenant::query()->findOrFail($other->tenantId), $this->admin, manualInvoiceData(['discount_amount' => null]));
        // Bisnis sendiri juga punya tagihan menunggu agar tombol bayar tampil
        app(CreateManualInvoice::class)->handle($this->tenant, $this->admin, manualInvoiceData());

        $owner = User::factory()->owner()->create(['tenant_id' => $this->tenant->id]);
        freshAuth();
        $this->actingAs($owner);
        Filament::setCurrentPanel('dashboard');
        TenantContext::set($this->tenant->id);

        Http::fake();
        $page = Livewire::test(SubscriptionSettings::class);
        // abort(404) di aksi: komponen tidak dirender ulang; yang penting tidak ada Snap untuk tagihan bisnis lain
        rescue(fn () => $page->callAction('payInvoice', ['method' => 'qris'], ['id' => $foreign->id]), report: false);

        Http::assertNothingSent();
        expect($foreign->refresh()->gateway_order_id)->toBeNull()->and($foreign->snap_url)->toBeNull();
    });
});
