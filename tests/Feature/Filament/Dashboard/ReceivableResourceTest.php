<?php

declare(strict_types=1);

use App\Actions\Member\UpdateCreditProgram;
use App\Actions\Member\UpdateMemberProgram;
use App\Enums\ReceivableEntryType;
use App\Filament\Dashboard\Resources\Receivables\Pages\ListReceivables;
use App\Filament\Dashboard\Resources\Receivables\ReceivableResource;
use App\Models\Member;
use App\Models\ReceivableEntry;
use App\Models\User;
use App\Services\Member\ReceivableLedger;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Pelanggan → Kasbon (ADR 0023). 22 Okt 2026. Batas bawaan Rp100.000.
 * Rina: kasbon 40.000 sejak 1 Sep (lunas sebagian 10.000 → 30.000) · Joko: 120.000 hari ini (melebihi batas)
 * · Sari: kasbon 20.000 lalu lunas.
 */
function receivableAt(Member $member, string $utc, ReceivableEntryType $type, string $amount): ReceivableEntry
{
    Carbon::setTestNow($utc);

    return app(ReceivableLedger::class)->record($member->refresh(), $type, $amount);
}

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    app(UpdateCreditProgram::class)->handle(true, '100000');

    $this->rina = Member::factory()->create(['name' => 'Rina']);
    $this->joko = Member::factory()->create(['name' => 'Joko']);
    $this->sari = Member::factory()->create(['name' => 'Sari']);
    $this->noCredit = Member::factory()->create(['name' => 'Tanpa Kasbon']);

    receivableAt($this->rina, '2026-09-01 03:00:00', ReceivableEntryType::Charge, '40000.00');
    receivableAt($this->rina, '2026-09-15 03:00:00', ReceivableEntryType::Payment, '-10000.00');
    receivableAt($this->sari, '2026-10-01 03:00:00', ReceivableEntryType::Charge, '20000.00');
    receivableAt($this->sari, '2026-10-05 03:00:00', ReceivableEntryType::Payment, '-20000.00');
    receivableAt($this->joko, '2026-10-22 03:00:00', ReceivableEntryType::Charge, '120000.00');

    Carbon::setTestNow('2026-10-22 05:00:00');
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
});

afterEach(fn () => Carbon::setTestNow());

it('daftar member berkasbon: saldo, umur sejak saldo naik dari nol, pelunasan terakhir', function () {
    Livewire::test(ListReceivables::class)
        ->assertCanSeeTableRecords([$this->rina, $this->joko, $this->sari])
        ->assertCanNotSeeTableRecords([$this->noCredit])
        ->assertSee('Rp30.000')->assertSee('Rp120.000')->assertSee('Lunas')
        // Rina: sejak 1 Sep (pelunasan sebagian tidak mengubah umur) = 51 hari
        ->assertSee('1 Sep 2026')->assertSee('51 hari')
        ->assertSee('Rp150.000');

    expect(ReceivableResource::ageDays(ReceivableResource::getEloquentQuery()->whereKey($this->rina->id)->sole()))->toBe(51)
        ->and(ReceivableResource::ageDays(ReceivableResource::getEloquentQuery()->whereKey($this->sari->id)->sole()))->toBeNull();

    $this->get(ReceivableResource::getUrl())->assertOk()->assertSee('Kasbon');
});

it('tab: belum lunas, lama (≥ 30 hari), melebihi batas, lunas', function () {
    Livewire::test(ListReceivables::class)
        ->set('activeTab', 'outstanding')->assertCanSeeTableRecords([$this->rina, $this->joko])->assertCanNotSeeTableRecords([$this->sari])
        ->set('activeTab', 'overdue')->assertCanSeeTableRecords([$this->rina])->assertCanNotSeeTableRecords([$this->joko, $this->sari])
        ->set('activeTab', 'over_limit')->assertCanSeeTableRecords([$this->joko])->assertCanNotSeeTableRecords([$this->rina])
        ->set('activeTab', 'settled')->assertCanSeeTableRecords([$this->sari])->assertCanNotSeeTableRecords([$this->rina, $this->joko]);
});

it('catat pelunasan dari daftar memakai Action yang sama dengan menu Member', function () {
    Livewire::test(ListReceivables::class)
        ->callAction(TestAction::make('payReceivable')->table($this->rina), [
            'amount' => '30000', 'payment_method_id' => $this->pos->methods['cash']->id, 'note' => 'Transfer',
        ])
        ->assertHasNoActionErrors();

    expect($this->rina->refresh()->receivable_balance)->toBe('0.00')
        ->and(ReceivableEntry::query()->where('member_id', $this->rina->id)->latest('created_at')->first()?->type)->toBe(ReceivableEntryType::Payment);
});

it('permission: kasir & supervisor ditolak; manager boleh, hanya baca', function () {
    $this->actingAs($this->pos->cashier);
    expect(ReceivableResource::canViewAny())->toBeFalse();
    $this->get(ReceivableResource::getUrl())->assertForbidden();

    $this->actingAs($this->pos->supervisor);
    expect(ReceivableResource::canViewAny())->toBeFalse();

    $manager = User::factory()->manager()->create(['tenant_id' => $this->pos->tenantId]);
    $this->actingAs($manager);
    CurrentOutlet::set($manager);
    expect(ReceivableResource::canViewAny())->toBeTrue()
        ->and(ReceivableResource::canCreate())->toBeFalse()
        ->and(ReceivableResource::canEdit($this->rina))->toBeFalse();
});

it('menu tersembunyi bila kasbon tidak aktif dan tidak ada sisa kasbon', function () {
    app(UpdateCreditProgram::class)->handle(false, null);
    expect(ReceivableResource::canViewAny())->toBeTrue(); // masih ada sisa kasbon Rina & Joko

    Member::query()->update(['receivable_balance' => '0.00']);
    expect(ReceivableResource::canViewAny())->toBeFalse();
});

it('isolasi tenant: kasbon tenant lain tidak terlihat', function () {
    $other = posSetup();
    $foreign = TenantContext::run($other->tenantId, function () {
        $member = Member::factory()->create(['name' => 'Member Tetangga']);
        receivableAt($member, '2026-10-20 03:00:00', ReceivableEntryType::Charge, '50000.00');

        return $member;
    });
    Carbon::setTestNow('2026-10-22 05:00:00');
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set($this->owner);

    Livewire::test(ListReceivables::class)->assertCanNotSeeTableRecords([$foreign])->assertDontSee('Member Tetangga');
});
