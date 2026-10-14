<?php

namespace App\Services;

use App\Enums\AppointmentType;
use App\Models\Appointment;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Books and moves appointments without double-booking anyone.
 *
 * Form requests check for clashes to give staff a clear error, but two
 * requests can pass validation at the same moment. So every write re-checks
 * inside a transaction while holding a clinic-wide booking lock, and only
 * then saves.
 */
class AppointmentScheduler
{
    private const LOCK = 'appointments:booking';

    /**
     * Book a new appointment.
     *
     * @param  array{doctor_id: int, type: AppointmentType, start_time: Carbon, end_time: Carbon, reason?: string|null}  $attributes
     *
     * @throws ValidationException when the time clashes or the schedule is busy
     */
    public function book(int $patientId, array $attributes, ?User $bookedBy): Appointment
    {
        return $this->exclusively(function () use ($patientId, $attributes, $bookedBy) {
            $this->ensureFree($attributes['doctor_id'], $patientId, $attributes['start_time'], $attributes['end_time']);

            $appointment = new Appointment(['patient_id' => $patientId, ...$attributes]);
            $appointment->created_by = $bookedBy?->id;
            $appointment->save();

            return $appointment;
        });
    }

    /**
     * Change an appointment's clinician, type, time or reason.
     *
     * @param  array{doctor_id: int, type: AppointmentType, start_time: Carbon, end_time: Carbon, reason?: string|null}  $attributes
     *
     * @throws ValidationException when the time clashes or the schedule is busy
     */
    public function reschedule(Appointment $appointment, array $attributes): Appointment
    {
        return $this->exclusively(function () use ($appointment, $attributes) {
            $this->ensureFree(
                $attributes['doctor_id'],
                $appointment->patient_id,
                $attributes['start_time'],
                $attributes['end_time'],
                $appointment,
            );

            $appointment->fill($attributes)->save();

            return $appointment;
        });
    }

    /**
     * Find clashes with the clinician's and the patient's other appointments.
     *
     * @return array<string, string> validation messages keyed by field
     */
    public function clashes(int $doctorId, int $patientId, Carbon $start, Carbon $end, ?Appointment $ignore = null): array
    {
        $clashes = [];

        $doctorClash = Appointment::forDoctor($doctorId)->overlapping($start, $end, $ignore)->orderBy('start_time')->first();

        if ($doctorClash !== null) {
            $clashes['doctor_id'] = 'The clinician already has an appointment '.$this->describe($doctorClash).'.';
        }

        $patientClash = Appointment::forPatient($patientId)->overlapping($start, $end, $ignore)->orderBy('start_time')->first();

        if ($patientClash !== null) {
            $clashes['start_time'] = 'The patient already has an appointment '.$this->describe($patientClash).'.';
        }

        return $clashes;
    }

    /**
     * @throws ValidationException
     */
    private function ensureFree(int $doctorId, int $patientId, Carbon $start, Carbon $end, ?Appointment $ignore = null): void
    {
        $clashes = $this->clashes($doctorId, $patientId, $start, $end, $ignore);

        if ($clashes !== []) {
            throw ValidationException::withMessages($clashes);
        }
    }

    /**
     * Run the callback in a transaction while holding the booking lock.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function exclusively(Closure $callback): mixed
    {
        try {
            return Cache::lock(self::LOCK, 10)->block(5, fn () => DB::transaction($callback));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'start_time' => 'The schedule is busy right now. Please try again.',
            ]);
        }
    }

    /**
     * Describe an appointment's time in the clinic's timezone. Only the time is
     * given, never who the other appointment is with.
     */
    private function describe(Appointment $appointment): string
    {
        $timezone = (string) config('clinic.timezone');

        return sprintf(
            'from %s to %s on %s',
            $appointment->start_time->copy()->setTimezone($timezone)->format('H:i'),
            $appointment->end_time->copy()->setTimezone($timezone)->format('H:i'),
            $appointment->start_time->copy()->setTimezone($timezone)->format('D j M Y'),
        );
    }
}
