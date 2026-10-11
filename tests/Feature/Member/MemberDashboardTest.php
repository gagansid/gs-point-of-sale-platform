<?php

declare(strict_types=1);

use App\Filament\Dashboard\Pages\MemberProgramSettings;
use App\Filament\Dashboard\Resources\Members\MemberResource;
use App\Filament\Dashboard\Resources\Members\Pages\ManageMembers;
use App\Models\ActivityLog;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\Outlet;
use App\Models\OutletMemberProgram;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** Dashboard member M1 (ADR 0013): menu Member & Pengaturan → Program member. */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->kemang = Outlet::factory()->create(['name' => 'Kemang']);
    TenantContext::set($this->kemang->tenant_id);
    $this->cilandak = Outlet::factory()->create(['name' => 'Cilandak']);
    $this->owner = User::factory()->owner()->create();
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
});

function enableMemberProgram(): void
{
    Livewire::test(MemberProgramSettings::class)->set('data.enabled', true)->call('save');
}

it('owner mengaktifkan program & mengecualikan outlet', function () {
    Livewire::test(MemberProgramSettings::class)
        ->assertSet('data.enabled', false)
        ->set('data.enabled', true)
        ->set('data.outlet_ids', [$this->kemang->id])
        ->call('save')
        ->assertNotified('Program member disimpan');

    expect(MemberSetting::current()->enabled)->toBeTrue()
        ->and(OutletMemberProgram::activeAt($this->kemang->id))->toBeTrue()
        ->and(OutletMemberProgram::activeAt($this->cilandak->id))->toBeFalse();
});

it('manager tidak bisa membuka Program member, tapi bisa mengelola member', function () {
    $this->actingAs(User::factory()->manager()->forOutlet($this->kemang)->create());

    $this->get(MemberProgramSettings::getUrl())->assertForbidden();
    expect(MemberResource::canViewAny())->toBeTrue();
});

it('supervisor (dashboard hanya Laporan) tidak melihat menu Member', function () {
    $this->actingAs(User::factory()->supervisor()->forOutlet($this->kemang)->create());

    expect(MemberResource::canViewAny())->toBeFalse();
    $this->get(MemberResource::getUrl())->assertForbidden();
});

it('tambah member dari dashboard: wajib persetujuan; program nonaktif = tombol Tambah hilang', function () {
    Livewire::test(ManageMembers::class)->assertActionHidden(TestAction::make('create')->table());
    enableMemberProgram();

    Livewire::test(ManageMembers::class)
        ->callAction(TestAction::make('create')->table(), ['name' => 'Rina', 'phone' => '0812-3456-7890', 'consent' => false])
        ->assertHasActionErrors(['consent']);

    Livewire::test(ManageMembers::class)
        ->callAction(TestAction::make('create')->table(), ['name' => 'Rina', 'phone' => '0812-3456-7890', 'birth_day' => 17, 'birth_month' => 8, 'consent' => true])
        ->assertHasNoActionErrors();

    $member = Member::query()->sole();
    expect($member->phone)->toBe('+6281234567890')->and($member->birth_month)->toBe(8);
});

it('ubah, nonaktifkan, dan cari member', function () {
    enableMemberProgram();
    $member = Member::factory()->create(['name' => 'Rina', 'phone' => '+6281234567890']);
    $other = Member::factory()->create(['name' => 'Budi']);

    Livewire::test(ManageMembers::class)
        ->searchTable('0812 3456 7890')->assertCanSeeTableRecords([$member])->assertCanNotSeeTableRecords([$other])
        ->callAction(TestAction::make('edit')->table($member), ['name' => 'Rina K', 'phone' => '081234567890'])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('deactivate')->table($member));

    expect($member->refresh()->name)->toBe('Rina K')->and($member->is_active)->toBeFalse();
});

it('hapus data (anonimkan): data pribadi & riwayat perubahannya hilang, order tetap', function () {
    enableMemberProgram();
    $member = Member::factory()->create(['name' => 'Rina', 'phone' => '+6281234567890', 'email' => 'rina@contoh.test']);
    $member->update(['name' => 'Rina Kusuma']);

    Livewire::test(ManageMembers::class)->callAction(TestAction::make('anonymize')->table($member));

    $member->refresh();
    expect($member->name)->toBe('Member dihapus')
        ->and($member->phone)->toBeNull()
        ->and($member->email)->toBeNull()
        ->and($member->anonymized_at)->not->toBeNull()
        ->and(ActivityLog::query()->where('subject_id', $member->id)->pluck('properties')->toJson())
        ->not->toContain('Rina')->not->toContain('6281234567890');

    // Nomor yang sama bisa mendaftar lagi
    Livewire::test(ManageMembers::class)
        ->callAction(TestAction::make('create')->table(), ['name' => 'Rina baru', 'phone' => '081234567890', 'consent' => true])
        ->assertHasNoActionErrors();
});

it('isolasi tenant: daftar hanya member bisnis sendiri', function () {
    enableMemberProgram();
    $mine = Member::factory()->create();
    TenantContext::forget();
    $foreignOutlet = Outlet::factory()->create();
    $foreign = TenantContext::run($foreignOutlet->tenant_id, fn () => Member::factory()->create());
    TenantContext::set($this->kemang->tenant_id);

    Livewire::test(ManageMembers::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$foreign]);
});
