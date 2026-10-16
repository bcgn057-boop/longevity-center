<?php

namespace App\Models;

use App\Enums\AssessmentKind;
use App\Exceptions\MedicalRecordRetentionException;
use App\Observers\FunctionalAssessmentObserver;
use Database\Factories\FunctionalAssessmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

/**
 * Measurements taken on one day. Values are encrypted. Entries are never
 * changed or deleted: a correction is recorded as a new entry.
 *
 * @property int $id
 * @property int $patient_id
 * @property AssessmentKind $kind
 * @property Carbon $measured_on
 * @property array<string, int|float> $measurements
 * @property string|null $notes
 * @property int|null $recorded_by
 * @property string $recorded_via
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Patient $patient
 * @property-read User|null $recorder
 */
#[ObservedBy(FunctionalAssessmentObserver::class)]
#[Fillable(['kind', 'measured_on', 'measurements', 'notes'])]
#[Hidden(['measurements', 'notes'])]
class FunctionalAssessment extends Model
{
    /** @use HasFactory<FunctionalAssessmentFactory> */
    use HasFactory;

    public const VIA_PORTAL = 'portal';

    public const VIA_CLINIC = 'clinic';

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
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Body mass index, when height and weight were both measured.
     */
    public function bmi(): ?float
    {
        $height = $this->measurements['height_cm'] ?? null;
        $weight = $this->measurements['weight_kg'] ?? null;

        return $height && $weight ? round($weight / (($height / 100) ** 2), 1) : null;
    }

    protected static function booted(): void
    {
        static::saving(function (FunctionalAssessment $assessment) {
            $allowed = array_keys($assessment->kind->fields());

            if ($assessment->measurements === [] || array_diff(array_keys($assessment->measurements), $allowed) !== []) {
                throw new InvalidArgumentException('An assessment needs at least one of its own measurements.');
            }

            if (! in_array($assessment->recorded_via, [self::VIA_PORTAL, self::VIA_CLINIC], true)) {
                throw new InvalidArgumentException('An assessment must be recorded through the portal or the clinic.');
            }

            if ($assessment->kind === AssessmentKind::AtCenter && $assessment->recorded_via !== self::VIA_CLINIC) {
                throw new LogicException('At-center tests are only recorded in the clinic.');
            }
        });

        static::updating(fn () => throw new LogicException('Assessments cannot be changed. Record a new entry instead.'));

        static::deleting(fn () => throw new MedicalRecordRetentionException(
            'Assessments must be retained and cannot be deleted.'
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AssessmentKind::class,
            'measured_on' => 'date',
            'measurements' => 'encrypted:array',
            'notes' => 'encrypted',
        ];
    }
}
