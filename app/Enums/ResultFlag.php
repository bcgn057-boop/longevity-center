<?php

namespace App\Enums;

/**
 * Where a value falls against the lab's own printed reference range.
 * Not a clinical interpretation.
 */
enum ResultFlag: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Below range',
            self::Normal => 'In range',
            self::High => 'Above range',
        };
    }
}
