<?php

namespace App\Http\Requests\Appointments;

use App\Models\Appointment;
use App\Models\User;
use App\Services\AppointmentScheduler;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Shared validation for booking and rescheduling appointments.
 *
 * Staff enter the date and times in the clinic's timezone; they are converted
 * to UTC when stored. A clash found here gives a friendly error, and the
 * scheduler checks again under a lock when saving.
 */
abstract class AppointmentRequest extends FormRequest
{
    public const MIN_MINUTES = 5;

    public const MAX_MINUTES = 240;

    /**
     * Determine if the user may write the reason for the visit.
     */
    abstract protected function canWriteReason(): bool;

    /**
     * Get the id of the patient being booked.
     */
    abstract public function patientId(): ?int;

    /**
     * Get the appointment being changed, if any, so it does not clash with itself.
     */
    abstract protected function appointmentBeingChanged(): ?Appointment;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'doctor_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'reason' => $this->canWriteReason()
                ? ['nullable', 'string', 'max:500']
                : ['prohibited'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.prohibited' => 'You are not allowed to record the reason for a visit.',
            'start_time.date_format' => 'Enter the start time as HH:MM.',
            'end_time.date_format' => 'Enter the end time as HH:MM.',
        ];
    }

    /**
     * Check the clinician, the time block and clashes once the basic rules pass.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! User::clinicians()->whereKey($this->integer('doctor_id'))->exists()) {
                $validator->errors()->add('doctor_id', 'Choose a doctor or practitioner.');
            }

            $start = $this->startsAt();
            $end = $this->endsAt();

            if (! $end->greaterThan($start)) {
                $validator->errors()->add('end_time', 'The end time must be after the start time.');
            } elseif ($start->diffInMinutes($end) < self::MIN_MINUTES || $start->diffInMinutes($end) > self::MAX_MINUTES) {
                $validator->errors()->add('end_time', sprintf(
                    'An appointment must last between %d minutes and %d hours.',
                    self::MIN_MINUTES,
                    self::MAX_MINUTES / 60,
                ));
            }

            if ($start->minute % 5 !== 0 || $end->minute % 5 !== 0) {
                $validator->errors()->add('start_time', 'Times must be in 5-minute steps.');
            }

            if (! $start->isFuture()) {
                $validator->errors()->add('start_time', 'The appointment must start in the future.');
            }

            $patientId = $this->patientId();

            if ($validator->errors()->isNotEmpty() || $patientId === null) {
                return;
            }

            $clashes = app(AppointmentScheduler::class)->clashes(
                $this->integer('doctor_id'),
                $patientId,
                $start,
                $end,
                $this->appointmentBeingChanged(),
            );

            foreach ($clashes as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        }];
    }

    /**
     * Get the attributes to save, with times converted from the clinic's timezone.
     *
     * @return array{doctor_id: int, start_time: Carbon, end_time: Carbon, reason?: string|null}
     */
    public function appointmentAttributes(): array
    {
        $attributes = [
            'doctor_id' => $this->integer('doctor_id'),
            'start_time' => $this->startsAt(),
            'end_time' => $this->endsAt(),
        ];

        if ($this->canWriteReason() && $this->exists('reason')) {
            $reason = trim((string) $this->input('reason'));
            $attributes['reason'] = $reason === '' ? null : $reason;
        }

        return $attributes;
    }

    private function startsAt(): Carbon
    {
        return $this->clinicTime((string) $this->input('start_time'));
    }

    private function endsAt(): Carbon
    {
        return $this->clinicTime((string) $this->input('end_time'));
    }

    private function clinicTime(string $time): Carbon
    {
        // "!" zeroes the fields not given, so seconds are 00 rather than now's.
        return Carbon::createFromFormat('!Y-m-d H:i', $this->input('date').' '.$time, (string) config('clinic.timezone'));
    }
}
