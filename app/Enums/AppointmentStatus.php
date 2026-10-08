<?php

namespace App\Enums;

/**
 * The lifecycle of an appointment. Completed and cancelled are final.
 */
enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Get the human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Determine if the appointment can move from this status to the given one.
     */
    public function canTransitionTo(self $status): bool
    {
        return $this === self::Scheduled && $status !== self::Scheduled;
    }

    /**
     * Determine if no further changes are allowed.
     */
    public function isFinal(): bool
    {
        return $this !== self::Scheduled;
    }
}
