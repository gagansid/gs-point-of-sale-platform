<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Actions;

use App\Actions\Billing\SetTenantAddons;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Models\Addon;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Support\Edition;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Atur add-on bisnis tanpa tagihan (ADR 0025 C1), seperti Atur paket: fitur = aktif/tidak, kuota = jumlah unit
 * (0 = lepas). Hanya bisnis berpaket di edisi SaaS.
 */
final class TenantAddonsAction
{
    public static function make(): Action
    {
        return Action::make('addons')->label('Atur add-on')->icon(Heroicon::OutlinedPuzzlePiece)->color('gray')
            ->visible(fn (Tenant $record): bool => Edition::isSaas() && $record->plan_id !== null && self::choices($record)->isNotEmpty())
            ->modalDescription('Berlaku langsung tanpa tagihan. Fitur yang sudah termasuk paket tidak perlu add-on')
            ->fillForm(fn (Tenant $record): array => [
                'addons' => TenantAddon::forTenant($record->id)->get()
                    ->mapWithKeys(fn (TenantAddon $a): array => [$a->addon_id => $a->quantity])->all(),
            ])
            ->schema(fn (Tenant $record): array => self::choices($record)->map(fn (Addon $addon) => $addon->isQuota()
                ? TextInput::make("addons.{$addon->id}")->label($addon->name)->integer()->minValue(0)->maxValue(999)
                    ->placeholder('0')->helperText($addon->benefit().' · '.Money::format($addon->price_monthly).'/bulan per unit')
                : Toggle::make("addons.{$addon->id}")->label($addon->name)
                    ->helperText($addon->benefit().' · '.Money::format($addon->price_monthly).'/bulan'))->all())
            ->action(function (Tenant $record, array $data, Action $action): void {
                RunBusinessAction::run(fn () => app(SetTenantAddons::class)->handle($record, (array) ($data['addons'] ?? [])), $action);
                Notification::make()->success()->title('Add-on disimpan')->send();
            });
    }

    /**
     * Add-on aktif + add-on nonaktif yang masih dimiliki bisnis (agar bisa dipertahankan/dilepas).
     *
     * @return Collection<int, Addon>
     */
    private static function choices(Tenant $tenant): Collection
    {
        $owned = TenantAddon::forTenant($tenant->id)->pluck('addon_id');

        return Addon::query()->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $owned))
            ->orderBy('sort_order')->orderBy('name')->get();
    }
}
