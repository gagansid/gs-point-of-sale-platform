<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Enums\ReportTab;
use App\Exceptions\ReportTooLargeException;
use App\Exports\ReportSectionBuilder;
use App\Exports\ReportWorkbookExport;
use App\Filament\Dashboard\Pages\Reports;
use App\Models\Order;
use App\Models\User;
use App\Services\Report\ReportContext;
use App\Services\Report\ReportExporter;
use App\Support\CurrentOutlet;
use App\Support\ExportThrottle;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/** Export laporan Excel & PDF (SPEC Q52, ADR 0012). */
function exportSale(stdClass $pos, string $utc): Order
{
    Carbon::setTestNow($utc);

    return TenantContext::run($pos->tenantId, fn () => app(CheckoutOrder::class)->handle($pos->cashier, $pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 50000]],
    ]))['order']);
}

function pdfContent(Response $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    TenantContext::run($this->pos->tenantId, fn () => $this->pos->croissant->update(['name' => '=HYPERLINK("http://jahat")']));

    exportSale($this->pos, '2026-10-08 03:00:00');
    Carbon::setTestNow('2026-10-10 05:00:00');
    TenantContext::set($this->pos->tenantId);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
});

afterEach(fn () => Carbon::setTestNow());

it('Excel lengkap: sheet Info + satu sheet per bagian, nominal angka, teks aman', function () {
    Excel::fake();

    Livewire::test(Reports::class)->callAction('excel_full');

    Excel::assertDownloaded('laporan-lengkap-2026-10-01-2026-10-10.xlsx', function (ReportWorkbookExport $export): bool {
        $sheets = $export->sheets();
        $titles = array_map(fn ($s) => $s->title(), $sheets);
        $products = collect($sheets)->first(fn ($s) => $s->title() === 'Per produk')->array();

        return $titles[0] === 'Info'
            && in_array('Pergerakan stok', $titles, true)
            && in_array('Transaksi void', $titles, true)
            && $products[0][0] === '\'=HYPERLINK("http://jahat")'
            && $products[0][2] === 24000.0; // omzet produk = line_total (sebelum pajak & service)
    });
});

it('Excel tab ini & nama file per tab', function () {
    Excel::fake();

    Livewire::test(Reports::class)->set('tab', 'payment_methods')->callAction('excel_tab');

    Excel::assertDownloaded('laporan-payment-methods-2026-10-01-2026-10-10.xlsx',
        fn (ReportWorkbookExport $export): bool => array_map(fn ($s) => $s->title(), $export->sheets()) === ['Info', 'Per metode bayar']);
});

it('PDF: application/pdf, tab ini & lengkap', function () {
    $context = ReportContext::fromInput(['preset' => 'this_month'], $this->owner);
    $response = app(ReportExporter::class)->pdf($this->owner, $context, ReportTab::Products);

    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('laporan-products-2026-10-01-2026-10-10.pdf')
        ->and(substr($pdf = pdfContent($response), 0, 4))->toBe('%PDF')
        // Font merek tertanam (resources/fonts, lisensi OFL), bukan fallback DejaVu Sans
        ->and($pdf)->toContain('PlusJakartaSans');

    Livewire::test(Reports::class)->callAction('pdf_full')->assertFileDownloaded('laporan-lengkap-2026-10-01-2026-10-10.pdf');
});

it('supervisor: export hanya tab operasional, tidak pernah Stok/Kategori/Outlet', function () {
    $this->actingAs($this->pos->supervisor);
    CurrentOutlet::set($this->pos->supervisor);

    expect(array_map(fn (ReportTab $t) => $t->value, ReportExporter::tabsFor($this->pos->supervisor, null)))
        ->toBe(['summary', 'products', 'payment_methods', 'employees', 'shifts', 'discounts'])
        ->and(ReportExporter::tabsFor($this->pos->supervisor, ReportTab::Stock))->toBe([]);

    Excel::fake();
    Livewire::test(Reports::class)->callAction('excel_full');
    Excel::assertDownloaded('laporan-lengkap-2026-10-01-2026-10-10.xlsx',
        fn (ReportWorkbookExport $export): bool => ! in_array('Pergerakan stok', array_map(fn ($s) => $s->title(), $export->sheets()), true));
});

it('kasir tanpa report.export: tombol export tidak ada', function () {
    $manager = User::factory()->manager()->create(['tenant_id' => $this->pos->tenantId]);
    $this->actingAs($manager);
    Livewire::test(Reports::class)->assertActionVisible('excel_full');

    expect($this->pos->cashier->can('report.export'))->toBeFalse();
});

it('data terlalu besar ditolak (tidak dipotong diam-diam)', function () {
    $context = ReportContext::fromInput(['preset' => 'this_month'], $this->owner);

    expect(fn () => app(ReportSectionBuilder::class)->build([ReportTab::Summary], $context, 3))
        ->toThrow(ReportTooLargeException::class, 'Persempit rentang tanggal');
});

it('isolasi tenant: export tidak memuat data tenant lain', function () {
    $other = posSetup();
    TenantContext::run($other->tenantId, fn () => $other->croissant->update(['name' => 'Roti Tenant Lain']));
    exportSale($other, '2026-10-08 03:00:00');
    Carbon::setTestNow('2026-10-10 05:00:00');
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set($this->owner);

    $sections = app(ReportSectionBuilder::class)->build([ReportTab::Products], ReportContext::fromInput(['preset' => 'this_month'], $this->owner), 1000);

    expect(array_column($sections[0]->rows, 0))->toBe(['=HYPERLINK("http://jahat")']);
});

it('API: PDF rekap shift untuk kasir pemilik shift; orang lain 403', function () {
    // Tanpa sesi owner dari beforeEach: hanya token API yang dipakai
    auth()->guard('web')->logout();
    TenantContext::forget();
    freshAuth();

    $other = User::factory()->cashier()->forOutlet($this->pos->outlet)->create();
    $this->withToken(userToken($other))->getJson("/api/v1/reports/shifts/{$this->pos->shift->id}/pdf", apiHeaders())->assertForbidden();

    freshAuth();
    $response = $this->withToken(userToken($this->pos->cashier))->get("/api/v1/reports/shifts/{$this->pos->shift->id}/pdf", apiHeaders());
    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and(substr($response->streamedContent(), 0, 4))->toBe('%PDF');
});

it('export dibatasi 10 kali per menit per user (R3)', function () {
    Excel::fake();
    config(['pos.rate_limits.export' => 2]);

    $page = Livewire::test(Reports::class);
    $page->callAction('excel_full');
    $page->callAction('excel_tab');
    $page->callAction('excel_full')->assertNotified();

    // Hanya dua file yang benar-benar dibuat
    Excel::assertDownloaded('laporan-lengkap-2026-10-01-2026-10-10.xlsx');
    expect(RateLimiter::attempts('export:'.$this->owner->id))->toBe(2);

    // User lain punya kuota sendiri
    $other = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    expect(ExportThrottle::attempt($other))->toBeNull();

    Carbon::setTestNow(now()->addSeconds(61));
    expect(ExportThrottle::attempt($this->owner))->toBeNull();
});

it('API PDF shift ikut batas export: 429 TOO_MANY_REQUESTS (R3)', function () {
    config(['pos.rate_limits.export' => 1]);
    $url = "/api/v1/reports/shifts/{$this->pos->shift->id}/pdf";
    $token = userToken($this->pos->cashier);

    $this->withToken($token)->get($url, apiHeaders())->assertOk();
    $this->withToken($token)->getJson($url, apiHeaders())->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
});
