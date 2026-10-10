<?php

declare(strict_types=1);

use App\Enums\SalesLeadStatus;
use App\Filament\Admin\Resources\SalesLeads\Pages\ListSalesLeads;
use App\Filament\Admin\Resources\SalesLeads\SalesLeadResource;
use App\Models\Admin;
use App\Models\SalesLead;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');
});

it('menampilkan calon pelanggan per status dan angka lead baru di menu', function () {
    $new = SalesLead::factory()->count(2)->create();
    $won = SalesLead::factory()->create(['status' => SalesLeadStatus::Won]);

    expect(SalesLeadResource::getNavigationBadge())->toBe('2');

    Livewire::test(ListSalesLeads::class)
        ->set('activeTab', 'new')
        ->assertCanSeeTableRecords($new)
        ->assertCanNotSeeTableRecords([$won]);
});

it('menandai lead dihubungi dan mengubah catatan', function () {
    $lead = SalesLead::factory()->create();

    Livewire::test(ListSalesLeads::class)->callAction(TestAction::make('markContacted')->table($lead));
    expect($lead->refresh()->status)->toBe(SalesLeadStatus::Contacted);

    Livewire::test(ListSalesLeads::class)
        ->callAction(TestAction::make('edit')->table($lead), ['status' => SalesLeadStatus::Won->value, 'notes' => 'Demo Senin'])
        ->assertHasNoActionErrors();

    expect($lead->refresh()->status)->toBe(SalesLeadStatus::Won)
        ->and($lead->notes)->toBe('Demo Senin');
});

it('lead tidak bisa dibuat dari panel & menu tertutup untuk akun tenant', function () {
    expect(SalesLeadResource::canCreate())->toBeFalse();

    auth('admin')->logout();
    $this->actingAs(User::factory()->owner()->create());

    // Akun tenant (guard web) ditolak panel admin: dialihkan ke login admin atau 403
    expect($this->get('/admin/sales-leads')->status())->toBeIn([302, 403]);
});
