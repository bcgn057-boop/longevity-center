<?php

namespace App\Enums;

/**
 * A prescription is active until a doctor discontinues it, which is final.
 */
enum PrescriptionStatus: string
{
    case Active = 'active';
    case Discontinued = 'discontinued';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Discontinued => 'Discontinued',
        };
    }
}
