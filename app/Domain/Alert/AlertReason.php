<?php

declare(strict_types=1);

namespace App\Domain\Alert;

enum AlertReason: string
{
    case NewMatch = 'new_match';
    case PriceDrop = 'price_drop';
}
