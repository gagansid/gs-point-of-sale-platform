<?php

declare(strict_types=1);

use App\Actions\Auth\Data\ApprovalData;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Shift\CloseShift;
use App\Actions\Shift\CorrectOpeningCash;
use App\Actions\Shift\Data\CashMovementData;
use App\Actions\Shift\RecordCashMovement;
use App\Actions\Shift\RecordShiftOperator;
use App\Filament\Dashboard\Pages\OutletSettings;
use App\Filament\Dashboard\Resources\Shifts\Pages\ViewShift;
use App\Models\Shift;
use App\Models\User;
use App\Services\Report\DailySummaryBuilder;
use App\Services\Report\ReportService;
use App\Services\Report\ShiftRecap;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Laporan & setelan shift bersama (ADR 0017, Q90–Q91): X/Z-report (kasir bertugas, kas masuk/keluar, disetor),
 * laporan Shift, panel Audit, ringkasan harian, detail shift dashboard, setelan laci kasir.
 */
beforeEach(function () {
    $this->pos = posSetup();
    $this->ani = User::factory()->cashier()->forOutlet($this->pos->outlet)->create(['name' => 'Ani']);

    // Budi buka & jual; Ani menggantikan & jual; kas keluar Rp20.000 + Rp150.000 (approval supervisor); kas awal dikoreksi
    TenantContext::run($this->pos->tenantId, function (): void {
        $shift = $this->pos->shift;
        app(RecordShiftOperator::class)->handle($this->pos->device, $this->pos->cashier);
        sharedSale($this->pos, $this->pos->cashier);
        app(RecordShiftOperator::class)->handle($this->pos->device, $this->ani);
        sharedSale($this->pos, $this->ani);
        sharedSale($this->pos, $this->ani);
        app(RecordCashMovement::class)->handle($this->ani, $this->pos->device, CashMovementData::fromArray(['id' => uuid(), 'type' => 'out', 'reason' => 'operational', 'amount' => '20000', 'note' => 'Galon']));
        app(RecordCashMovement::class)->handle($this->ani, $this->pos->device, CashMovementData::fromArray(['id' => uuid(), 'type' => 'out', 'reason' => 'deposit', 'amount' => '150000']),
            new ApprovalData($this->pos->supervisor->id, '123456'));
        app(CorrectOpeningCash::class)->handle($this->ani, $shift, $this->pos->device, '190000', 'Salah hitung', new ApprovalData($this->pos->supervisor->id, '123456'));
    });
});

/** 1 Croissant tunai = 28.000 setelah service, pajak & pembulatan. */
function sharedSale(stdClass $pos, User $cashier): void
{
    app(CheckoutOrder::class)->handle($cashier, $pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 50000]],
    ]));
}

function closeSharedShift(stdClass $pos): Shift
{
    // Seharusnya 190.000 + 84.000 − 170.000 = 104.000
    return TenantContext::run($pos->tenantId, fn (): Shift => app(CloseShift::class)
        ->handle($pos->ani ?? $pos->cashier, $pos->shift->refresh(), $pos->device, '104000', '54000', null)['shift']);
}

it('Z-report API: kasir bertugas, kas masuk/keluar, koreksi kas awal, disetor & sisa laci', function () {
    closeSharedShift($this->pos);

    freshAuth();
    $recap = $this->withToken(userToken($this->ani, $this->pos->device))
        ->getJson("/api/v1/reports/shifts/{$this->pos->shift->id}", apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.type', 'z_report')
        ->assertJsonPath('data.cash.opening_cash', '190000.00')
        ->assertJsonPath('data.cash.carried_cash', '200000.00')
        ->assertJsonPath('data.cash.cash_out', '170000.00')
        ->assertJsonPath('data.cash.expected_cash', '104000.00')
        ->assertJsonPath('data.cash.difference', '0.00')
        ->assertJsonPath('data.cash.cash_deposited', '54000.00')
        ->assertJsonPath('data.cash.closing_float', '50000.00')
        ->assertJsonPath('data.shift.opening_cash_source', 'adjusted')
        ->assertJsonPath('data.shift.opening_cash_approved_by', 'Sari')
        ->assertJsonCount(2, 'data.cash_movements')
        ->assertJsonPath('data.cash_movements.1.approved_by', 'Sari')
        ->json('data.operators');

    expect(collect($recap)->pluck('order_count', 'name')->all())->toBe(['Budi' => 1, 'Ani' => 2])
        ->and(collect($recap)->firstWhere('name', 'Ani')['cash_sales'])->toBe('56000.00')
        ->and(collect($recap)->firstWhere('name', 'Budi')['periods'][0]['ended_at'])->not->toBeNull();
});

it('Z-report PDF & halaman kasir web memuat kasir bertugas dan kas keluar', function () {
    closeSharedShift($this->pos);

    freshAuth();
    $this->withToken(userToken($this->ani, $this->pos->device))
        ->get("/api/v1/reports/shifts/{$this->pos->shift->id}/pdf", apiHeaders())
        ->assertOk()->assertHeader('content-type', 'application/pdf');

    $html = view('reports.pdf.shift', [
        'recap' => TenantContext::run($this->pos->tenantId, fn () => app(ShiftRecap::class)->for($this->pos->shift->refresh())),
        'timezone' => 'Asia/Jakarta', 'tenant' => 'Kopi', 'printedBy' => 'Ani', 'printedAt' => 'sekarang',
    ])->render();
    expect($html)->toContain('Kasir bertugas')->toContain('Galon')->toContain('Disetor')->toContain('dikoreksi dari');
});

it('laporan Shift: kas masuk/keluar & disetor; panel Audit: kas keluar & kas awal dikoreksi', function () {
    closeSharedShift($this->pos);
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set(User::factory()->owner()->create());
    [$start, $end] = [CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay()];

    $row = app(ReportService::class)->shifts($start, $end)[0];
    expect($row['cash_in'])->toBe('0.00')->and($row['cash_out'])->toBe('170000.00')->and($row['cash_deposited'])->toBe('54000.00');

    $audit = app(ReportService::class)->audit($start, $end);
    expect($audit['cash_out_count'])->toBe(2)
        ->and($audit['cash_out_total'])->toBe('170000.00')
        ->and($audit['cash_outs'][0]['approved_by'])->toBe('Sari')
        ->and($audit['shift_issue_count'])->toBe(1)
        ->and($audit['shifts'][0]['opening_adjustment'])->toMatchArray(['from' => '200000.00', 'to' => '190000.00', 'by' => 'Ani', 'approved_by' => 'Sari']);
});

it('ringkasan harian menjumlahkan disetor', function () {
    closeSharedShift($this->pos);
    $owner = TenantContext::run($this->pos->tenantId, fn () => User::factory()->owner()->create());

    $summary = TenantContext::run($this->pos->tenantId, fn () => app(DailySummaryBuilder::class)->build($owner, $this->pos->outlet->localDate()));
    expect($summary['totals']['cash_deposited'])->toBe('54000.00');
});

describe('dashboard', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('dashboard');
        TenantContext::set($this->pos->tenantId);
        $this->owner = User::factory()->owner()->create();
        $this->actingAs($this->owner);
        CurrentOutlet::set($this->owner);
    });

    it('detail shift menampilkan kasir bertugas, kas masuk/keluar, koreksi kas awal; tutup paksa dengan disetor', function () {
        Livewire::test(ViewShift::class, ['record' => $this->pos->shift->id])
            ->assertSee('Kasir bertugas')->assertSee('Ani')->assertSee('Galon')->assertSee('Setor ke owner')
            ->assertSee('Dikoreksi dari')
            ->callAction(TestAction::make('forceClose'), ['actual_cash' => '104000', 'cash_deposited' => '104001', 'note' => 'x'])
            ->assertNotified();
        expect($this->pos->shift->refresh()->isOpen())->toBeTrue();

        Livewire::test(ViewShift::class, ['record' => $this->pos->shift->id])
            ->callAction(TestAction::make('forceClose'), ['actual_cash' => '104000', 'cash_deposited' => '4000', 'note' => 'Tablet rusak'])
            ->assertHasNoFormErrors();

        expect($this->pos->shift->refresh()->closing_float)->toBe('100000.00');
        Livewire::test(ViewShift::class, ['record' => $this->pos->shift->id])->assertSee('Ditinggal di laci');
    });

    it('Profil outlet: setelan laci kasir tersimpan, tercermin di /auth/me', function () {
        Livewire::test(OutletSettings::class)
            ->assertSet('data.auto_lock_minutes', 5)
            ->set('data.cash_float', '500000')
            ->set('data.auto_lock_minutes', '')
            ->set('data.cash_out_approval_limit', '50000')
            ->call('save')
            ->assertHasNoFormErrors();

        $outlet = $this->pos->outlet->refresh();
        expect($outlet->cash_float)->toBe('500000.00')->and($outlet->auto_lock_minutes)->toBeNull()->and($outlet->cash_out_approval_limit)->toBe('50000.00');

        Livewire::test(OutletSettings::class)->set('data.auto_lock_minutes', '121')->call('save')->assertHasFormErrors(['auto_lock_minutes']);

        freshAuth();
        $this->withToken($this->pos->token)->getJson('/api/v1/auth/me', apiHeaders())
            ->assertJsonPath('data.outlet.cash_float', '500000.00')
            ->assertJsonPath('data.outlet.auto_lock_minutes', null)
            ->assertJsonPath('data.outlet.cash_out_approval_limit', '50000.00');
    });

    it('PUT /outlet: field laci yang tidak dikirim tetap', function () {
        $this->pos->outlet->forceFill(['cash_float' => '300000'])->save();

        freshAuth();
        $this->withToken(userToken($this->owner, $this->pos->device))->putJson('/api/v1/outlet', ['auto_lock_minutes' => 10], apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.auto_lock_minutes', 10)
            ->assertJsonPath('data.cash_float', '300000.00')
            ->assertJsonPath('data.cash_out_approval_limit', '100000.00');

        // Batas retur per outlet (ADR 0018): 0–365 hari
        freshAuth();
        $this->withToken(userToken($this->owner, $this->pos->device))->putJson('/api/v1/outlet', ['refund_max_days' => 7], apiHeaders())
            ->assertOk()->assertJsonPath('data.refund_max_days', 7);
        freshAuth();
        $this->withToken(userToken($this->owner, $this->pos->device))->putJson('/api/v1/outlet', ['refund_max_days' => 400], apiHeaders())->assertStatus(422);
    });
});
