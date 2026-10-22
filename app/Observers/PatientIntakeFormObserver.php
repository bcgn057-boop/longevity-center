<?php

namespace App\Observers;

use App\Enums\AuditEvent;
use App\Enums\IntakeStatus;
use App\Intake\IntakeSchema;
use App\Models\PatientIntakeForm;
use App\Services\AuditLogger;

/**
 * Audits intake forms being started, sent, answered and completed, by
 * section name only. Never records an answer.
 */
class PatientIntakeFormObserver
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function updated(PatientIntakeForm $form): void
    {
        $sections = array_values(array_intersect(
            array_keys($form->getChanges()),
            [...IntakeSchema::PATIENT_SECTIONS, IntakeSchema::CLINICAL_SECTION],
        ));

        if ($form->wasChanged('status') && $form->status === IntakeStatus::Completed) {
            $this->audit->record(AuditEvent::IntakeCompleted, auditable: $form, metadata: ['patient_id' => $form->patient_id]);
        } elseif ($form->wasChanged('token_hash') && $form->token_hash !== null) {
            $this->audit->record(AuditEvent::IntakeSent, auditable: $form, metadata: ['patient_id' => $form->patient_id]);
        }

        if ($sections !== []) {
            $this->audit->record(AuditEvent::IntakeAnswered, auditable: $form, metadata: [
                'patient_id' => $form->patient_id,
                'sections' => $sections,
            ]);
        }
    }

    public function created(PatientIntakeForm $form): void
    {
        $this->audit->record(AuditEvent::IntakeStarted, auditable: $form, metadata: [
            'patient_id' => $form->patient_id,
            'status' => $form->status->value,
        ]);
    }
}
