<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Siklus tagihan langganan (ADR 0024). */
enum BillingCycle: string implements HasLabel
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function getLabel(): string
    {
        return $this === self::Monthly ? 'Bulanan' : 'Tahunan';
    }

    public function months(): int
    {
        return $this === self::Monthly ? 1 : 12;
    }
}
