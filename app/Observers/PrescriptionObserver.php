<?php

namespace App\Observers;

use App\Enums\AuditEvent;
use App\Enums\PrescriptionStatus;
use App\Models\Prescription;
use App\Services\AuditLogger;

/**
 * Audits prescriptions being issued, edited and discontinued, by field name
 * only. Never records the title, notes or reason.
 */
class PrescriptionObserver
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function created(Prescription $prescription): void
    {
        $this->audit->record(
            AuditEvent::PrescriptionIssued,
            auditable: $prescription,
            metadata: ['patient_id' => $prescription->patient_id],
        );
    }

    public function updated(Prescription $prescription): void
    {
        $changed = array_values(array_diff(array_keys($prescription->getChanges()), ['updated_at']));

        if ($changed === []) {
            return;
        }

        if ($prescription->wasChanged('status') && $prescription->status === PrescriptionStatus::Discontinued) {
            $this->audit->record(AuditEvent::PrescriptionDiscontinued, auditable: $prescription);

            return;
        }

        $this->audit->record(AuditEvent::PrescriptionUpdated, auditable: $prescription, metadata: ['fields' => $changed]);
    }
}
