<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Actions;

use App\Actions\Billing\SetTenantPlan;
use App\Enums\BillingCycle;
use App\Models\Plan;
use App\Models\Tenant;
use App\Support\Edition;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** Atur paket tenant tanpa tagihan (ADR 0024): paket kosong = semua fitur. Masa aktif diatur di form Ubah. */
final class TenantPlanAction
{
    public static function make(): Action
    {
        return Action::make('plan')->label('Atur paket')->icon(Heroicon::OutlinedRectangleStack)->color('gray')
            ->visible(fn (): bool => Edition::isSaas())
            ->fillForm(fn (Tenant $record): array => ['plan_id' => $record->plan_id, 'billing_cycle' => ($record->billing_cycle ?? BillingCycle::Monthly)->value])
            ->schema([
                Select::make('plan_id')->label('Paket')->placeholder('Tanpa paket (semua fitur)')
                    ->options(fn (): array => Plan::query()->orderBy('sort_order')->pluck('name', 'id')->all()),
                ToggleButtons::make('billing_cycle')->label('Siklus')->inline()->options(BillingCycle::class),
            ])
            ->action(function (Tenant $record, array $data): void {
                $plan = filled($data['plan_id'] ?? null) ? Plan::query()->findOrFail($data['plan_id']) : null;
                $cycle = $data['billing_cycle'] instanceof BillingCycle ? $data['billing_cycle'] : BillingCycle::tryFrom((string) ($data['billing_cycle'] ?? ''));
                app(SetTenantPlan::class)->handle($record, $plan, $cycle);
                Notification::make()->success()->title('Paket disimpan')->send();
            });
    }
}
