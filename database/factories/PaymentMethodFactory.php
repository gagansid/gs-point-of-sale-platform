<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentCategory;
use App\Models\PaymentMethod;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethod>
 */
final class PaymentMethodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => TenantContext::id() ?? Tenant::factory(),
            'name' => 'Tunai',
            'category' => PaymentCategory::Cash,
            'requires_reference' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
