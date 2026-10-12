<?php

namespace App\Enums;

/**
 * The kind of intervention a protocol item asks the patient to follow.
 */
enum ProtocolItemType: string
{
    case Medication = 'medication';
    case Supplement = 'supplement';
    case Nutrition = 'nutrition';
    case Exercise = 'exercise';
    case Sleep = 'sleep';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Medication => 'Medication',
            self::Supplement => 'Supplement',
            self::Nutrition => 'Nutrition',
            self::Exercise => 'Exercise',
            self::Sleep => 'Sleep',
            self::Other => 'Other',
        };
    }
}
