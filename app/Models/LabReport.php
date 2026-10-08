<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\LabReportStatus;
use App\Exceptions\MedicalRecordRetentionException;
use App\Observers\LabReportObserver;
use Database\Factories\LabReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One blood draw or lab report for a patient.
 *
 * Patients only see it once a doctor or practitioner has released it, and a
 * released report is final: later fixes are corrections to its results.
 * Reports are never deleted.
 *
 * @property int $id
 * @property int $patient_id
 * @property Carbon $collected_at
 * @property string|null $lab_name
 * @property LabReportStatus $status
 * @property string|null $notes
 * @property int|null $entered_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Patient $patient
 * @property-read User|null $enteredBy
 * @property-read User|null $reviewer
 */
#[ObservedBy(LabReportObserver::class)]
#[Fillable(['collected_at', 'lab_name', 'notes'])]
#[Hidden(['notes'])]
class LabReport extends Model
{
    /** @use HasFactory<LabReportFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => LabReportStatus::PendingReview->value,
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
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Every result ever recorded on the report, including corrected ones.
     *
     * @return HasMany<LabResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(LabResult::class);
    }

    /**
     * The results currently in force: those not replaced by a correction.
     *
     * @return HasMany<LabResult, $this>
     */
    public function currentResults(): HasMany
    {
        return $this->results()->current();
    }

    public function isReleased(): bool
    {
        return $this->status === LabReportStatus::Released;
    }

    /**
     * Release the report to the patient after review.
     */
    public function release(User $reviewer): void
    {
        if ($this->isReleased()) {
            throw new LogicException('This report has already been released.');
        }

        $this->forceFill([
            'status' => LabReportStatus::Released,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ])->save();
    }

    protected static function booted(): void
    {
        static::updating(function (LabReport $report) {
            if (LabReportStatus::from($report->getRawOriginal('status')) === LabReportStatus::Released) {
                throw new LogicException('A released report cannot be changed. Record a correction to its results instead.');
            }
        });

        static::deleting(fn () => throw new MedicalRecordRetentionException(
            'Lab reports must be retained and cannot be deleted.'
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collected_at' => UtcDateTime::class,
            'status' => LabReportStatus::class,
            'notes' => 'encrypted',
            'reviewed_at' => 'datetime',
        ];
    }
}
