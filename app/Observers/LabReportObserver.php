<?php

namespace App\Observers;

use App\Enums\AuditEvent;
use App\Models\LabReport;
use App\Services\AuditLogger;

/**
 * Audits lab reports being entered, edited and released, by field name only.
 */
class LabReportObserver
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function created(LabReport $report): void
    {
        $this->audit->record(
            AuditEvent::LabReportCreated,
            auditable: $report,
            metadata: ['patient_id' => $report->patient_id],
        );
    }

    public function updated(LabReport $report): void
    {
        $changed = array_values(array_diff(array_keys($report->getChanges()), ['updated_at']));

        if ($changed === []) {
            return;
        }

        if ($report->wasChanged('status') && $report->isReleased()) {
            $this->audit->record(AuditEvent::LabReportReleased, auditable: $report);

            return;
        }

        $this->audit->record(AuditEvent::LabReportUpdated, auditable: $report, metadata: ['fields' => $changed]);
    }
}
