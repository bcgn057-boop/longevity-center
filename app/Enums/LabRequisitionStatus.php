<?php

namespace App\Enums;

/**
 * A requisition is ordered, then reported once the lab enters results for
 * it. A clinician can cancel it before then.
 */
enum LabRequisitionStatus: string
{
    case Ordered = 'ordered';
    case Reported = 'reported';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Ordered => 'Ordered',
            self::Reported => 'Reported',
            self::Cancelled => 'Cancelled',
        };
    }
}
