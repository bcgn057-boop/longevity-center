<?php

namespace App\Http\Requests\Appointments;

use App\Models\Appointment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The optional cancellation reason. It is encrypted, and only users who may
 * read the visit's reason may write it.
 */
class CancelAppointmentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $appointment = $this->route('appointment');

        $canWrite = $appointment instanceof Appointment && (bool) $this->user()?->can('viewReason', $appointment);

        return [
            'cancellation_reason' => $canWrite ? ['nullable', 'string', 'max:500'] : ['prohibited'],
        ];
    }

    public function cancellationReason(): ?string
    {
        $reason = trim((string) $this->validated('cancellation_reason'));

        return $reason === '' ? null : $reason;
    }
}
