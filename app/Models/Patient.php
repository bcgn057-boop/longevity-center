<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\Sex;
use App\Exceptions\MedicalRecordRetentionException;
use App\Observers\PatientObserver;
use App\Reminders\ReceivesReminders;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * A patient's profile. Identifying and medical fields are encrypted at rest.
 * Records are archived (soft deleted), never permanently removed.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $medical_record_number
 * @property string $first_name
 * @property string $last_name
 * @property Sex $sex
 * @property string $date_of_birth
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $medical_history
 * @property string|null $allergies
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $full_name
 * @property-read User|null $user
 */
#[ObservedBy(PatientObserver::class)]
#[Fillable([
    'first_name',
    'last_name',
    'sex',
    'date_of_birth',
    'email',
    'phone',
    'address',
    'medical_history',
    'allergies',
])]
#[Hidden(['date_of_birth', 'email', 'phone', 'address', 'medical_history', 'allergies'])]
class Patient extends Model implements ReceivesReminders
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * Fields that contain medical information. Only clinical roles may read or
     * write them, and their values never appear in the audit log.
     *
     * @var array<int, string>
     */
    public const MEDICAL_FIELDS = ['medical_history', 'allergies'];

    /**
     * Get the user account linked to this patient, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the patient's emergency contacts.
     *
     * @return HasMany<EmergencyContact, $this>
     */
    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmergencyContact::class)->orderBy('id');
    }

    /**
     * Get the patient's appointments.
     *
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Get the patient's lab reports.
     *
     * @return HasMany<LabReport, $this>
     */
    public function labReports(): HasMany
    {
        return $this->hasMany(LabReport::class);
    }

    /**
     * Get the patient's measurements and functional tests, newest first.
     *
     * @return HasMany<FunctionalAssessment, $this>
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(FunctionalAssessment::class)->orderByDesc('measured_on')->orderByDesc('id');
    }

    /**
     * Get the prescriptions issued to the patient.
     *
     * @return HasMany<Prescription, $this>
     */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    /**
     * Get the care reminders written for the patient.
     *
     * @return HasMany<CareReminder, $this>
     */
    public function careReminders(): HasMany
    {
        return $this->hasMany(CareReminder::class);
    }

    /**
     * Get the address reminders on the channel go to, or null if there is none.
     * In-app reminders go to the linked portal account.
     */
    public function reminderDestination(NotificationChannel $channel): ?string
    {
        $destination = match ($channel) {
            NotificationChannel::Database => $this->user_id === null ? null : (string) $this->user_id,
            NotificationChannel::Mail => $this->email,
            NotificationChannel::WhatsApp => $this->phone,
        };

        return blank($destination) ? null : $destination;
    }

    public function routeNotificationForMail(): ?string
    {
        return $this->reminderDestination(NotificationChannel::Mail);
    }

    public function routeNotificationForWhatsApp(): ?string
    {
        return $this->reminderDestination(NotificationChannel::WhatsApp);
    }

    /**
     * In-app notifications are stored in the linked account's inbox.
     *
     * @return MorphMany<DatabaseNotification, User>
     */
    public function routeNotificationForDatabase(): MorphMany
    {
        return $this->user()->firstOrFail()->notifications();
    }

    /**
     * Get the patient's full name.
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Get the patient's age in whole years.
     */
    public function age(): int
    {
        return (int) Carbon::parse($this->date_of_birth)->diffInYears(now());
    }

    /**
     * Search by name or medical record number.
     *
     * @param  Builder<Patient>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        // Bound as a parameter, so wildcards typed by staff only widen the search.
        $like = '%'.$term.'%';

        $query->where(function (Builder $query) use ($like) {
            $query->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('medical_record_number', 'like', $like)
                ->orWhereRaw("first_name || ' ' || last_name like ?", [$like]);
        });
    }

    /**
     * Medical records must be retained: forbid permanent deletion.
     */
    protected static function booted(): void
    {
        static::forceDeleting(fn () => throw new MedicalRecordRetentionException);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sex' => Sex::class,
            'date_of_birth' => 'encrypted',
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'address' => 'encrypted',
            'medical_history' => 'encrypted',
            'allergies' => 'encrypted',
        ];
    }
}
