<?php

namespace App\Observers;

use App\Enums\AuditEvent;
use App\Models\Patient;
use App\Services\AuditLogger;

/**
 * Audits every write to a patient record, whatever code path made it.
 *
 * Entries name the fields that changed but never their values, so no
 * identifying or medical data is copied into the (unencrypted) audit log.
 */
class PatientObserver
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function created(Patient $patient): void
    {
        $patient->medical_record_number = config('clinic.mrn_prefix')
            .str_pad((string) $patient->id, (int) config('clinic.mrn_padding'), '0', STR_PAD_LEFT);
        $patient->saveQuietly();

        $this->audit->record(
            AuditEvent::PatientCreated,
            auditable: $patient,
            metadata: ['medical_record_number' => $patient->medical_record_number],
        );
    }

    public function updated(Patient $patient): void
    {
        $changed = array_values(array_diff(array_keys($patient->getChanges()), ['updated_at']));

        if ($changed === ['user_id']) {
            $this->recordAccountChange($patient);

            return;
        }

        if ($changed === []) {
            return;
        }

        $this->audit->record(
            AuditEvent::PatientUpdated,
            auditable: $patient,
            metadata: ['fields' => $changed],
        );
    }

    public function deleted(Patient $patient): void
    {
        $this->audit->record(AuditEvent::PatientArchived, auditable: $patient);
    }

    public function restored(Patient $patient): void
    {
        $this->audit->record(AuditEvent::PatientRestored, auditable: $patient);
    }

    private function recordAccountChange(Patient $patient): void
    {
        $previous = $patient->getOriginal('user_id');

        $this->audit->record(
            $patient->user_id === null ? AuditEvent::PatientAccountUnlinked : AuditEvent::PatientAccountLinked,
            auditable: $patient,
            oldValues: ['user_id' => $previous],
            newValues: ['user_id' => $patient->user_id],
        );
    }
}
