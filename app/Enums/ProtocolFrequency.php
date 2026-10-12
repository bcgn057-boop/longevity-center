<?php

namespace App\Enums;

/**
 * How often a protocol item is to be followed. Daily items are the ones the
 * patient ticks off on their dashboard.
 */
enum ProtocolFrequency: string
{
    case Daily = 'daily';
    case TwiceDaily = 'twice_daily';
    case Weekly = 'weekly';
    case AsNeeded = 'as_needed';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Once a day',
            self::TwiceDaily => 'Twice a day',
            self::Weekly => 'Once a week',
            self::AsNeeded => 'As needed',
        };
    }

    /**
     * Whether the patient tracks this item with a checkbox each day.
     */
    public function isDaily(): bool
    {
        return in_array($this, [self::Daily, self::TwiceDaily], true);
    }
}
