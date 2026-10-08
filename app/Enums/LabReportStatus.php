<?php

namespace App\Enums;

/**
 * A report waits for a doctor or practitioner to review it; once released,
 * the patient can see it.
 */
enum LabReportStatus: string
{
    case PendingReview = 'pending_review';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending review',
            self::Released => 'Released',
        };
    }
}
