<?php

declare(strict_types=1);

use App\Livewire\Cashier\PointOfSale;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Shift;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use App\Support\WebCashier;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/** Dialog item kasir web: opsi bawaan diatur owner (Q129), ketuk opsi, stepper qty. */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    $device = webCashierLogin($this, $this->pos, $this->pos->cashier);
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set($this->pos->cashier, $this->pos->outlet->id);
    WebCashier::setDevice($device);
    Auth::shouldUse(WebCashier::GUARD);
    Shift::factory()->forDevice($device, $this->pos->cashier)->create(['opening_cash' => '100000.00']);

    $option = fn (OptionGroup $group, string $name, string $price): Option => Option::factory()
        ->create(['option_group_id' => $group->id, 'tenant_id' => $this->pos->tenantId, 'name' => $name, 'price_delta' => $price]);

    // Opsional pilih satu dengan bawaan "Normal" (diatur owner)
    $this->sugar = OptionGroup::factory()->create(['name' => 'Gula', 'min_select' => 0, 'max_select' => 1]);
    $this->normal = $option($this->sugar, 'Normal', '0.00');
    $this->normal->update(['is_default' => true]);
    $this->less = $option($this->sugar, 'Less sugar', '0.00');
    // Opsional berbayar: tidak pernah terpilih otomatis
    $this->topping = OptionGroup::factory()->create(['name' => 'Topping', 'min_select' => 0, 'max_select' => 2]);
    $this->boba = $option($this->topping, 'Boba', '4000.00');
    $this->jelly = $option($this->topping, 'Jelly', '3000.00');
    $this->cheese = $option($this->topping, 'Keju', '5000.00');
    $this->pos->coffee->optionGroups()->attach([$this->sugar->id => ['sort_order' => 1], $this->topping->id => ['sort_order' => 2]]);
});

it('bawaan: wajib tanpa bawaan = opsi pertama, opsi bawaan terpilih, opsional tanpa bawaan kosong', function () {
    Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->coffee->id)
        ->assertSet("pickOptions.{$this->pos->size->id}", $this->pos->regular->id)
        ->assertSet("pickOptions.{$this->sugar->id}", $this->normal->id)
        ->assertSet("pickOptions.{$this->topping->id}", [])
        ->assertDontSee('Tidak dipilih');
});

it('ketuk opsi: ganti pilihan, ketuk lagi melepas opsional; grup wajib tidak bisa dikosongkan', function () {
    Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->coffee->id)
        ->call('pickOption', $this->sugar->id, $this->less->id)->assertSet("pickOptions.{$this->sugar->id}", $this->less->id)
        ->call('pickOption', $this->sugar->id, $this->less->id)->assertSet("pickOptions.{$this->sugar->id}", '')
        ->call('pickOption', $this->pos->size->id, $this->pos->regular->id)->assertSet("pickOptions.{$this->pos->size->id}", $this->pos->regular->id)
        ->call('pickOption', $this->pos->size->id, $this->pos->large->id)->assertSet("pickOptions.{$this->pos->size->id}", $this->pos->large->id);
});

it('ketuk opsi multi: maks. max_select; opsi grup lain / acak diabaikan', function () {
    Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->coffee->id)
        ->call('pickOption', $this->topping->id, $this->boba->id)
        ->call('pickOption', $this->topping->id, $this->jelly->id)
        ->call('pickOption', $this->topping->id, $this->cheese->id)
        ->assertSet("pickOptions.{$this->topping->id}", [$this->boba->id, $this->jelly->id])
        ->call('pickOption', $this->topping->id, $this->boba->id)
        ->assertSet("pickOptions.{$this->topping->id}", [$this->jelly->id])
        ->call('pickOption', $this->topping->id, $this->normal->id)
        ->call('pickOption', 'bukan-grup', $this->boba->id)
        ->assertSet("pickOptions.{$this->topping->id}", [$this->jelly->id]);
});

it('stepper qty 1–999 lalu masuk keranjang dengan opsi bawaan', function () {
    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->coffee->id)
        ->call('stepPickQty', -1)->assertSet('pickQty', 1)
        ->call('stepPickQty', 1)->call('stepPickQty', 1)->assertSet('pickQty', 3)
        ->set('pickQty', 999)->call('stepPickQty', 1)->assertSet('pickQty', 999)
        ->set('pickQty', 2)->call('savePick')->assertSet('pickProductId', null);

    expect($page->get('cart.0.qty'))->toBe(2)
        ->and($page->get('cart.0.option_names'))->toBe(['Regular', 'Normal']);
});
