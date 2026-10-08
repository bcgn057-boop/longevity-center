<?php

namespace App\Observers;

use App\Enums\AppointmentStatus;
use App\Enums\AuditEvent;
use App\Models\Appointment;
use App\Services\AuditLogger;

/**
 * Audits every write to an appointment, whatever code path made it.
 *
 * Entries name the fields that changed and record status transitions, but
 * never copy the encrypted reason or cancellation reason into the audit log.
 */
class AppointmentObserver
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function created(Appointment $appointment): void
    {
        $this->audit->record(
            AuditEvent::AppointmentCreated,
            auditable: $appointment,
            metadata: [
                'patient_id' => $appointment->patient_id,
                'doctor_id' => $appointment->doctor_id,
                'status' => $appointment->status->value,
            ],
        );
    }

    public function updated(Appointment $appointment): void
    {
        $changed = array_values(array_diff(array_keys($appointment->getChanges()), ['updated_at']));

        if ($changed === []) {
            return;
        }

        if ($appointment->wasChanged('status')) {
            $this->audit->record(
                $appointment->status === AppointmentStatus::Cancelled
                    ? AuditEvent::AppointmentCancelled
                    : AuditEvent::AppointmentCompleted,
                auditable: $appointment,
                oldValues: ['status' => $appointment->getOriginal('status')?->value],
                newValues: ['status' => $appointment->status->value],
                metadata: ['fields' => $changed],
            );

            return;
        }

        $this->audit->record(
            $appointment->wasChanged(['start_time', 'end_time'])
                ? AuditEvent::AppointmentRescheduled
                : AuditEvent::AppointmentUpdated,
            auditable: $appointment,
            metadata: ['fields' => $changed],
        );
    }
}
