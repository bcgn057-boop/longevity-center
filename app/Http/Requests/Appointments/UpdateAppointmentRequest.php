<?php

namespace App\Http\Requests\Appointments;

use App\Models\Appointment;

/**
 * Rescheduling: the clinician, date, times and reason may change, the patient may not.
 */
class UpdateAppointmentRequest extends AppointmentRequest
{
    public function patientId(): ?int
    {
        return $this->appointmentBeingChanged()?->patient_id;
    }

    protected function canWriteReason(): bool
    {
        $appointment = $this->appointmentBeingChanged();

        return $appointment !== null && (bool) $this->user()?->can('viewReason', $appointment);
    }

    protected function appointmentBeingChanged(): ?Appointment
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment ? $appointment : null;
    }
}
