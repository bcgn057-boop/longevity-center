<?php

namespace App\Http\Requests\Appointments;

use App\Models\Appointment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends AppointmentRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'patient_id' => [
                'required',
                'integer',
                Rule::exists('patients', 'id')->whereNull('deleted_at'),
            ],
            ...parent::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'patient_id.required' => 'Choose a patient.',
            'patient_id.exists' => 'Choose an active patient.',
        ];
    }

    public function patientId(): ?int
    {
        return $this->filled('patient_id') ? $this->integer('patient_id') : null;
    }

    protected function canWriteReason(): bool
    {
        return (bool) $this->user()?->can('viewReason', Appointment::class);
    }

    protected function appointmentBeingChanged(): ?Appointment
    {
        return null;
    }
}
