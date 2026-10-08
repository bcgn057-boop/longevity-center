<?php

namespace App\Observers;

use App\Enums\AuditEvent;
use App\Models\LabResult;
use App\Services\AuditLogger;

/**
 * Audits results being recorded and corrected. Never records the value,
 * unit, range or comment.
 */
class LabResultObserver
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function created(LabResult $result): void
    {
        $metadata = [
            'lab_report_id' => $result->lab_report_id,
            'biomarker_id' => $result->biomarker_id,
        ];

        if ($result->supersedes_id !== null) {
            $this->audit->record(
                AuditEvent::LabResultCorrected,
                auditable: $result,
                metadata: [...$metadata, 'supersedes_id' => $result->supersedes_id],
            );

            return;
        }

        $this->audit->record(AuditEvent::LabResultRecorded, auditable: $result, metadata: $metadata);
    }
}
