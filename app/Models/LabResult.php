<?php

namespace App\Models;

use App\Enums\ResultFlag;
use App\Exceptions\MedicalRecordRetentionException;
use App\Labs\LabValue;
use App\Observers\LabResultObserver;
use Database\Factories\LabResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

/**
 * One measured value on a lab report, stored as the lab reported it.
 *
 * Results are never changed or deleted. A correction is a new result that
 * points to the one it replaces, so the full history is kept.
 *
 * @property int $id
 * @property int $lab_report_id
 * @property int $biomarker_id
 * @property string $value
 * @property string $unit
 * @property string|null $reference_low
 * @property string|null $reference_high
 * @property string|null $comment
 * @property string|null $correction_reason
 * @property int|null $supersedes_id
 * @property int|null $entered_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LabReport $report
 * @property-read Biomarker $biomarker
 * @property-read LabResult|null $supersedes
 * @property-read LabResult|null $successor
 * @property-read User|null $enteredBy
 */
#[ObservedBy(LabResultObserver::class)]
#[Fillable(['biomarker_id', 'value', 'unit', 'reference_low', 'reference_high', 'comment'])]
#[Hidden(['value', 'comment', 'correction_reason'])]
class LabResult extends Model
{
    /** @use HasFactory<LabResultFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<LabReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(LabReport::class, 'lab_report_id');
    }

    /**
     * @return BelongsTo<Biomarker, $this>
     */
    public function biomarker(): BelongsTo
    {
        return $this->belongsTo(Biomarker::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /**
     * The result this one corrects, if any.
     *
     * @return BelongsTo<LabResult, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * The correction that replaced this result, if any.
     *
     * @return HasOne<LabResult, $this>
     */
    public function successor(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_id');
    }

    /**
     * Results not replaced by a correction.
     *
     * @param  Builder<LabResult>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereDoesntHave('successor');
    }

    public function labValue(): LabValue
    {
        return LabValue::parse($this->value);
    }

    /**
     * Where the value falls against the lab's printed range, worked out when
     * shown so it is never stored in plain text.
     */
    public function flag(): ?ResultFlag
    {
        return $this->labValue()->flag($this->reference_low, $this->reference_high);
    }

    /**
     * Record a correction: a new result replacing this one on the same report.
     *
     * @param  array{value: string, unit: string, reference_low?: string|null, reference_high?: string|null, comment?: string|null, biomarker_id?: int}  $attributes
     * @param  string  $reason  why the correction was made, e.g. "transcription error"
     */
    public function correct(array $attributes, string $reason, ?User $by): self
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A correction needs a reason.');
        }

        if ($this->successor()->exists()) {
            throw new LogicException('This result has already been corrected. Correct the latest version instead.');
        }

        $correction = new self([...$attributes, 'biomarker_id' => $this->biomarker_id]);
        $correction->lab_report_id = $this->lab_report_id;
        $correction->supersedes_id = $this->id;
        $correction->correction_reason = trim($reason);
        $correction->entered_by = $by?->id;
        $correction->save();

        return $correction;
    }

    protected static function booted(): void
    {
        static::saving(function (LabResult $result) {
            $result->value = (string) LabValue::parse($result->value);

            $biomarker = Biomarker::find($result->biomarker_id);

            if ($biomarker === null || ! $biomarker->allowsUnit($result->unit)) {
                throw new InvalidArgumentException("The unit [{$result->unit}] is not allowed for this biomarker.");
            }

            if ($result->reference_low !== null && $result->reference_high !== null
                && (float) $result->reference_low > (float) $result->reference_high) {
                throw new InvalidArgumentException('The reference range low must not be above the high.');
            }
        });

        static::creating(function (LabResult $result) {
            // New measurements go on reports still under review; a released
            // report only changes through corrections.
            if ($result->supersedes_id === null && LabReport::find($result->lab_report_id)?->isReleased()) {
                throw new LogicException('Results cannot be added to a released report.');
            }
        });

        static::updating(fn () => throw new LogicException('Lab results cannot be changed. Record a correction instead.'));

        static::deleting(fn () => throw new MedicalRecordRetentionException(
            'Lab results must be retained and cannot be deleted.'
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'comment' => 'encrypted',
            'correction_reason' => 'encrypted',
            'reference_low' => 'decimal:4',
            'reference_high' => 'decimal:4',
        ];
    }
}
