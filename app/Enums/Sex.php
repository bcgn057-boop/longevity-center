<?php

namespace App\Enums;

/**
 * Sex recorded at birth. Clinically relevant for reference ranges.
 */
enum Sex: string
{
    case Female = 'female';
    case Male = 'male';
    case Intersex = 'intersex';
    case Unknown = 'unknown';

    /**
     * Get the human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Female => 'Female',
            self::Male => 'Male',
            self::Intersex => 'Intersex',
            self::Unknown => 'Unknown',
        };
    }
}
