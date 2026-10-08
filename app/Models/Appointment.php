<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\AppointmentStatus;
use App\Exceptions\InvalidAppointmentTransitionException;
use App\Exceptions\MedicalRecordRetentionException;
use App\Observers\AppointmentObserver;
use Database\Factories\AppointmentFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A booked session between a patient and a clinician. Times are stored in UTC.
 *
 * The status only changes through complete() and cancel(), and completed or
 * cancelled appointments are final. Appointments are never deleted.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $doctor_id
 * @property Carbon $start_time
 * @property Carbon $end_time
 * @property AppointmentStatus $status
 * @property string|null $reason
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancellation_reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Patient $patient
 * @property-read User $doctor
 * @property-read User|null $canceller
 */
#[ObservedBy(AppointmentObserver::class)]
#[Fillable(['patient_id', 'doctor_id', 'start_time', 'end_time', 'reason'])]
#[Hidden(['reason', 'cancellation_reason'])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    /**
     * New appointments start as scheduled.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => AppointmentStatus::Scheduled->value,
    ];

    /**
     * Get the patient being seen.
     *
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        // Archived patients keep their appointment history.
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    /**
     * Get the clinician running the appointment.
     *
     * @return BelongsTo<User, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /**
     * Get the user who cancelled the appointment, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Determine if the appointment has started.
     */
    public function hasStarted(): bool
    {
        return $this->start_time->lessThanOrEqualTo(now());
    }

    /**
     * Mark the appointment as completed.
     */
    public function complete(): void
    {
        $this->status = AppointmentStatus::Completed;
        $this->save();
    }

    /**
     * Cancel the appointment, recording who cancelled it and why.
     */
    public function cancel(?User $by, ?string $reason = null): void
    {
        $this->forceFill([
            'status' => AppointmentStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $by?->id,
            'cancellation_reason' => $reason,
        ])->save();
    }

    /**
     * Appointments that clash with the given time block. Cancelled appointments
     * free their slot, and back-to-back appointments do not clash.
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeOverlapping(Builder $query, DateTimeInterface $start, DateTimeInterface $end, ?Appointment $ignore = null): void
    {
        $query->where('status', '!=', AppointmentStatus::Cancelled)
            ->where('start_time', '<', Carbon::instance($end)->utc()->format('Y-m-d H:i:s'))
            ->where('end_time', '>', Carbon::instance($start)->utc()->format('Y-m-d H:i:s'))
            ->when($ignore?->exists, fn (Builder $query) => $query->whereKeyNot($ignore?->getKey()));
    }

    /**
     * Appointments run by the given clinician.
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeForDoctor(Builder $query, User|int $doctor): void
    {
        $query->where('doctor_id', $doctor instanceof User ? $doctor->id : $doctor);
    }

    /**
     * Appointments for the given patient.
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeForPatient(Builder $query, Patient|int $patient): void
    {
        $query->where('patient_id', $patient instanceof Patient ? $patient->id : $patient);
    }

    /**
     * Enforce the time, status and retention rules on every write.
     */
    protected static function booted(): void
    {
        static::saving(function (Appointment $appointment) {
            if (! $appointment->end_time->greaterThan($appointment->start_time)) {
                throw new InvalidArgumentException('An appointment must end after it starts.');
            }
        });

        static::updating(function (Appointment $appointment) {
            $original = AppointmentStatus::from($appointment->getRawOriginal('status'));

            if ($original->isFinal()) {
                throw InvalidAppointmentTransitionException::finalised($original);
            }

            if ($appointment->isDirty('status') && ! $original->canTransitionTo($appointment->status)) {
                throw InvalidAppointmentTransitionException::between($original, $appointment->status);
            }
        });

        static::deleting(fn () => throw new MedicalRecordRetentionException(
            'Appointments must be retained and cannot be deleted. Cancel them instead.'
        ));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_time' => UtcDateTime::class,
            'end_time' => UtcDateTime::class,
            'status' => AppointmentStatus::class,
            'reason' => 'encrypted',
            'cancelled_at' => 'datetime',
            'cancellation_reason' => 'encrypted',
        ];
    }
}
