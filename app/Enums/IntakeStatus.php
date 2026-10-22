<?php

namespace App\Enums;

/**
 * A draft is being filled in at the clinic; a form sent to the patient waits
 * for them to submit it. Completed forms are read-only to the patient.
 */
enum IntakeStatus: string
{
    case Draft = 'draft';
    case SentToPatient = 'sent_to_patient';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Being filled in at the clinic',
            self::SentToPatient => 'Sent to the patient',
            self::Completed => 'Completed',
        };
    }
}
