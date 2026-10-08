<?php

declare(strict_types=1);

namespace App\Enums;

enum DiscountType: string
{
    case Fixed = 'fixed';
    case Percent = 'percent';
}
