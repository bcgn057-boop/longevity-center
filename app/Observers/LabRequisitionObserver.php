<?php

namespace App\Observers;

use App\Enums\AuditEvent;
use App\Enums\LabRequisitionStatus;
use App\Models\LabRequisition;
use App\Services\AuditLogger;

/**
 * Audits requisitions being ordered and cancelled. Records the partner,
 * never the tests or instructions, which can reveal a condition.
 */
class LabRequisitionObserver
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function created(LabRequisition $requisition): void
    {
        $this->audit->record(AuditEvent::LabRequisitionOrdered, auditable: $requisition, metadata: [
            'patient_id' => $requisition->patient_id,
            'lab_partner_id' => $requisition->lab_partner_id,
        ]);
    }

    public function updated(LabRequisition $requisition): void
    {
        if ($requisition->wasChanged('status') && $requisition->status === LabRequisitionStatus::Cancelled) {
            $this->audit->record(AuditEvent::LabRequisitionCancelled, auditable: $requisition);
        }
    }
}
