<?php

namespace App\Observers;

use App\Enums\AuditEvent;
use App\Models\FunctionalAssessment;
use App\Services\AuditLogger;

/**
 * Audits assessments being recorded: which fields, never their values.
 */
class FunctionalAssessmentObserver
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function created(FunctionalAssessment $assessment): void
    {
        $this->audit->record(AuditEvent::AssessmentRecorded, auditable: $assessment, metadata: [
            'patient_id' => $assessment->patient_id,
            'kind' => $assessment->kind->value,
            'via' => $assessment->recorded_via,
            'fields' => array_keys($assessment->measurements),
        ]);
    }
}
