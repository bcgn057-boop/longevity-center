<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Presents an appointment with its times in the clinic's timezone, so pages
 * never depend on the browser's timezone.
 *
 * The type (a general visit or a therapy) is shown to everyone who can see
 * the appointment, as the front desk schedules by it. The free-text reason
 * and cancellation reason are only included for viewers allowed to read
 * them, and never in list rows.
 *
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    private bool $includeReasons = true;

    /**
     * Leave out the reasons, e.g. for list rows.
     */
    public function withoutReasons(): static
    {
        $this->includeReasons = false;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = (string) config('clinic.timezone');
        $start = $this->start_time->copy()->setTimezone($timezone);
        $end = $this->end_time->copy()->setTimezone($timezone);

        $showReasons = $this->includeReasons && (bool) $request->user()?->can('viewReason', $this->resource);

        return [
            'id' => $this->id,
            'patient' => [
                'id' => $this->patient->id,
                'full_name' => $this->patient->full_name,
                'medical_record_number' => $this->patient->medical_record_number,
            ],
            'doctor' => [
                'id' => $this->doctor->id,
                'name' => $this->doctor->name,
            ],
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'date' => $start->format('Y-m-d'),
            'date_label' => $start->format('D j M Y'),
            'start' => $start->format('H:i'),
            'end' => $end->format('H:i'),
            'duration_minutes' => (int) $start->diffInMinutes($end),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'has_started' => $this->hasStarted(),
            'reason' => $this->when($showReasons, fn () => $this->reason),
            'cancellation_reason' => $this->when($showReasons, fn () => $this->cancellation_reason),
            'cancelled_at' => $this->cancelled_at?->copy()->setTimezone($timezone)->format('D j M Y H:i'),
            'cancelled_by' => $this->canceller?->name,
        ];
    }
}
