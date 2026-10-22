<?php

namespace App\Models;

use App\Enums\IntakeStatus;
use App\Exceptions\MedicalRecordRetentionException;
use App\Intake\IntakeSchema;
use App\Observers\PatientIntakeFormObserver;
use Database\Factories\PatientIntakeFormFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A patient's intake questionnaire: the patient's answers by section, plus
 * the clinician's functional assessment and scores. Every section is
 * encrypted. Forms are never deleted.
 *
 * @property int $id
 * @property int $patient_id
 * @property int|null $doctor_id
 * @property IntakeStatus $status
 * @property string|null $token_hash
 * @property Carbon|null $token_expires_at
 * @property array<string, mixed>|null $demographics
 * @property array<string, mixed>|null $conditions
 * @property array<string, mixed>|null $surgeries
 * @property array<int, array<string, mixed>>|null $medications
 * @property array<string, mixed>|null $lifestyle
 * @property array<string, mixed>|null $family_history
 * @property array<string, mixed>|null $vaccinations
 * @property array<string, mixed>|null $womens_health
 * @property array<string, mixed>|null $clinical
 * @property Carbon|null $sent_at
 * @property Carbon|null $submitted_at
 * @property int|null $submitted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Patient $patient
 * @property-read User|null $doctor
 */
#[ObservedBy(PatientIntakeFormObserver::class)]
#[Hidden(['token_hash', 'demographics', 'conditions', 'surgeries', 'medications', 'lifestyle', 'family_history', 'vaccinations', 'womens_health', 'clinical'])]
class PatientIntakeForm extends Model
{
    /** @use HasFactory<PatientIntakeFormFactory> */
    use HasFactory;

    /**
     * How long an emailed link works.
     */
    public const LINK_DAYS = 14;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => IntakeStatus::Draft->value,
    ];

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /**
     * Forms not yet completed.
     *
     * @param  Builder<PatientIntakeForm>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', '!=', IntakeStatus::Completed);
    }

    public function isCompleted(): bool
    {
        return $this->status === IntakeStatus::Completed;
    }

    /**
     * Whether the patient may still change their answers.
     */
    public function isWithPatient(): bool
    {
        return $this->status === IntakeStatus::SentToPatient;
    }

    /**
     * The form an emailed link points to, if the link is genuine and in date.
     */
    public static function findByToken(string $token): ?self
    {
        return self::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('token_expires_at', '>', now())
            ->first();
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new MedicalRecordRetentionException(
            'Intake forms must be retained and cannot be deleted.'
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IntakeStatus::class,
            'token_expires_at' => 'datetime',
            ...array_fill_keys([...IntakeSchema::PATIENT_SECTIONS, IntakeSchema::CLINICAL_SECTION], 'encrypted:array'),
            'sent_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }
}
