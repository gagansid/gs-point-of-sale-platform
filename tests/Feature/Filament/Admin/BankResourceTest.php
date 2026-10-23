<?php

declare(strict_types=1);

use App\Actions\Admin\SaveBank;
use App\Exceptions\BusinessException;
use App\Filament\Admin\Resources\Banks\Pages\ManageBanks;
use App\Models\Admin;
use App\Models\Bank;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** Langganan → Bank (Q124): master bank untuk rekening transfer manual, logo vektor / unggahan. */
beforeEach(function () {
    Storage::fake('public');
    Filament::setCurrentPanel('admin');
    $this->admin = Admin::factory()->withTwoFactor()->create();
    $this->actingAs($this->admin, 'admin');
});

it('migrasi mengisi bank umum Indonesia dengan warna merek', function () {
    expect(Bank::query()->count())->toBeGreaterThanOrEqual(10)
        ->and(Bank::query()->where('code', 'bca')->value('color'))->toBe('#0060af')
        ->and(Bank::options())->toHaveKey(Bank::query()->where('code', 'mandiri')->value('id'));
});

it('admin menambah & mengubah bank; kode unik, warna hex, logo hanya raster di folder banks', function () {
    Livewire::test(ManageBanks::class)
        ->callAction(TestAction::make('create')->table(), ['code' => 'bjb', 'name' => 'Bank BJB', 'short_name' => 'bjb', 'color' => '#005baa', 'text_color' => '#ffffff', 'is_active' => true, 'sort_order' => 20])
        ->assertHasNoActionErrors();
    $bjb = Bank::query()->where('code', 'bjb')->sole();
    expect($bjb->created_by)->toBe($this->admin->id);

    $save = app(SaveBank::class);
    expect(fn () => $save->handle(null, ['code' => 'bca', 'name' => 'Duplikat', 'short_name' => 'X']))->toThrow(BusinessException::class, 'sudah dipakai')
        ->and(fn () => $save->handle($bjb, [...$bjb->only(['code', 'name', 'short_name']), 'color' => 'merah']))->toThrow(BusinessException::class, 'hex')
        ->and(fn () => $save->handle($bjb, [...$bjb->only(['code', 'name', 'short_name', 'color', 'text_color']), 'logo_path' => 'banks/logo.svg']))->toThrow(BusinessException::class, 'PNG')
        ->and(fn () => $save->handle($bjb, [...$bjb->only(['code', 'name', 'short_name', 'color', 'text_color']), 'logo_path' => '../.env']))->toThrow(BusinessException::class);

    Storage::disk('public')->put('banks/lama.png', 'x');
    $save->handle($bjb, [...$bjb->only(['code', 'name', 'short_name', 'color', 'text_color']), 'logo_path' => 'banks/lama.png']);
    $save->handle($bjb->refresh(), [...$bjb->only(['code', 'name', 'short_name', 'color', 'text_color']), 'logo_path' => null]);
    Storage::disk('public')->assertMissing('banks/lama.png');

    Livewire::test(ManageBanks::class)
        ->callAction(TestAction::make('deactivate')->table($bjb))
        ->set('activeTab', 'inactive')->assertCanSeeTableRecords([$bjb]);
    expect($bjb->refresh()->is_active)->toBeFalse()->and(Bank::options())->not->toHaveKey($bjb->id);
});

it('logo vektor memakai singkatan & warna; logo unggahan memakai gambar', function () {
    $bank = Bank::query()->where('code', 'bni')->sole();
    $svg = Blade::render('<x-bank-logo :bank="$bank" />', ['bank' => $bank]);
    expect($svg)->toContain('<svg')->toContain('>BNI</text>')->toContain('#f15a23');

    $bank->forceFill(['logo_path' => 'banks/bni.png'])->save();
    expect(Blade::render('<x-bank-logo :bank="$bank" />', ['bank' => $bank]))->toContain('<img')->toContain('banks/bni.png');
});
