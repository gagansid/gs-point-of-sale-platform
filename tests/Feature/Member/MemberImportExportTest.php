<?php

declare(strict_types=1);

use App\Actions\Member\ImportMembers;
use App\Actions\Member\UpdateMemberProgram;
use App\Exceptions\BusinessException;
use App\Exports\MembersExport;
use App\Filament\Dashboard\Resources\Members\Pages\ManageMembers;
use App\Models\Member;
use App\Models\Outlet;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

/** Import/export member (ADR 0013, M7), pola import produk (Q59). */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->outlet = Outlet::factory()->create(['name' => 'Kemang']);
    TenantContext::set($this->outlet->tenant_id);
    app(UpdateMemberProgram::class)->handle(true, [$this->outlet->id]);
    $this->owner = User::factory()->owner()->create();
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner, $this->outlet->id);
});

function memberRow(array $override = []): array
{
    return ['nama' => 'Rina Kusuma', 'nomor_hp' => '0812 3456 7890', 'email' => 'Rina@Contoh.id', 'tanggal_lahir' => 17, 'bulan_lahir' => 8, 'catatan' => null, ...$override];
}

/** @return array<string, list<string>> */
function memberImportErrors(array $rows, bool $consent = true): array
{
    try {
        app(ImportMembers::class)->handle($rows, $consent);
    } catch (BusinessException $e) {
        return $e->details ?? [];
    }

    return [];
}

it('member baru dibuat; nomor HP terdaftar diperbarui tanpa mengubah kode & poin; HP dari Excel tanpa 0 diterima', function () {
    $existing = Member::factory()->create(['name' => 'Budi Lama', 'phone' => '+6285711112222', 'points_balance' => 40]);

    $result = app(ImportMembers::class)->handle([
        memberRow(),
        memberRow(['nama' => 'Budi Santoso', 'nomor_hp' => 85711112222, 'email' => null, 'tanggal_lahir' => null, 'bulan_lahir' => null]),
    ], true, $this->outlet);

    expect($result)->toBe(['created' => 1, 'updated' => 1]);

    $rina = Member::query()->where('phone', '+6281234567890')->sole();
    expect($rina->email)->toBe('rina@contoh.id')->and($rina->birth_month)->toBe(8)
        ->and($rina->consent_at)->not->toBeNull()->and($rina->joined_outlet_id)->toBe($this->outlet->id);

    $existing->refresh();
    expect($existing->name)->toBe('Budi Santoso')->and($existing->points_balance)->toBe(40)->and(Member::query()->count())->toBe(2);
});

it('validasi semua baris dulu: satu salah = tidak ada yang disimpan; persetujuan wajib', function () {
    $errors = memberImportErrors([
        memberRow(),
        memberRow(['nama' => '=HYPERLINK("x")', 'nomor_hp' => '12345']),
        memberRow(['nama' => 'Kembar']),
        memberRow(['nama' => 'Tgl', 'nomor_hp' => '0813 0000 1111', 'tanggal_lahir' => 31, 'bulan_lahir' => 2]),
    ]);

    expect(array_keys($errors))->toBe(['baris 3', 'baris 4', 'baris 5'])
        ->and(implode(' ', $errors['baris 3']))->toContain('tidak boleh diawali')->toContain('nomor HP tidak valid')
        ->and(implode(' ', $errors['baris 4']))->toContain('lebih dari sekali')
        ->and(implode(' ', $errors['baris 5']))->toContain('tanggal lahir tidak valid')
        ->and(Member::query()->count())->toBe(0);

    expect(memberImportErrors([memberRow()], consent: false))->toHaveKey('consent')
        ->and(Member::query()->count())->toBe(0);
});

it('isolasi tenant: nomor HP yang sama di bisnis lain = member baru di bisnis ini', function () {
    TenantContext::forget();
    $other = Outlet::factory()->create();
    $foreign = TenantContext::run($other->tenant_id, fn () => Member::factory()->create(['name' => 'Asing', 'phone' => '+6281234567890']));

    TenantContext::set($this->outlet->tenant_id);
    expect(app(ImportMembers::class)->handle([memberRow()], true))->toBe(['created' => 1, 'updated' => 0])
        ->and(Member::allTenants()->whereKey($foreign->id)->value('name'))->toBe('Asing');
});

it('lewat halaman Member: unggah CSV dengan persetujuan; file sementara dihapus; template bisa diunduh', function () {
    $csv = "nama,nomor_hp,email,tanggal_lahir,bulan_lahir,catatan\nRina,081234567890,,,,\nBudi,0857 1111 2222,,,,VIP\n";

    Livewire::test(ManageMembers::class)
        ->callAction('import', ['file' => UploadedFile::fake()->createWithContent('member.csv', $csv), 'consent' => false])
        ->assertHasActionErrors(['consent']);

    Livewire::test(ManageMembers::class)
        ->callAction('import', ['file' => UploadedFile::fake()->createWithContent('member.csv', $csv), 'consent' => true])
        ->assertHasNoActionErrors()
        ->assertNotified('Import selesai');

    expect(Member::query()->pluck('name')->sort()->values()->all())->toBe(['Budi', 'Rina'])
        ->and(Storage::disk('local')->files('imports'))->toBe([]);

    Livewire::test(ManageMembers::class)
        ->mountAction('import')
        ->callAction(TestAction::make('template')->arguments([]))
        ->assertFileDownloaded('template-import-member.xlsx');
});

it('export Excel: member aktif tanpa yang dihapus, teks aman; kasir/supervisor tidak melihat tombol', function () {
    Excel::fake();
    Member::factory()->create(['name' => '=1+1', 'phone' => '+6281200000001']);
    Member::factory()->create(['name' => 'Member dihapus', 'phone' => null, 'anonymized_at' => now()]);

    Livewire::test(ManageMembers::class)->callAction('export');

    Excel::assertDownloaded('member-'.now(CurrentOutlet::timezone())->format('Y-m-d').'.xlsx', function (MembersExport $export): bool {
        $rows = $export->query()->get();

        return $rows->count() === 1 && $export->map($rows->first())[1] === "'=1+1";
    });

    $manager = User::factory()->manager()->forOutlet($this->outlet)->create();
    $this->actingAs($manager);
    Livewire::test(ManageMembers::class)->assertActionVisible('export')->assertActionVisible('import');
});
