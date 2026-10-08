<?php

namespace App\Enums;

/**
 * A care reminder is scheduled until it is sent or cancelled; both are final.
 */
enum CareReminderStatus: string
{
    case Scheduled = 'scheduled';
    case Sent = 'sent';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Sent => 'Sent',
            self::Cancelled => 'Cancelled',
        };
    }
}
