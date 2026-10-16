<?php

namespace App\Models;

use App\Enums\LabRequisitionStatus;
use App\Enums\UserRole;
use App\Exceptions\MedicalRecordRetentionException;
use App\Observers\LabRequisitionObserver;
use Database\Factories\LabRequisitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

/**
 * A clinician's order for tests from one lab partner, for one patient.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $lab_partner_id
 * @property array<int, string> $tests
 * @property string|null $instructions
 * @property LabRequisitionStatus $status
 * @property int|null $ordered_by
 * @property Carbon|null $reported_at
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Patient $patient
 * @property-read LabPartner $labPartner
 * @property-read User|null $orderer
 */
#[ObservedBy(LabRequisitionObserver::class)]
#[Fillable(['tests', 'instructions'])]
#[Hidden(['instructions'])]
class LabRequisition extends Model
{
    /** @use HasFactory<LabRequisitionFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => LabRequisitionStatus::Ordered->value,
    ];

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    /**
     * @return BelongsTo<LabPartner, $this>
     */
    public function labPartner(): BelongsTo
    {
        return $this->belongsTo(LabPartner::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function orderer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    /**
     * @return HasMany<LabReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(LabReport::class);
    }

    /**
     * Requisitions the user may see: lab users only their own partner's,
     * and none without a partner. Clinicians see all.
     *
     * @param  Builder<LabRequisition>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->hasRole(UserRole::LabStaff)) {
            $query->where('lab_partner_id', $user->lab_partner_id ?? 0)->whereNotNull('lab_partner_id');
        }
    }

    /**
     * Still waiting for, or able to take, a report.
     *
     * @param  Builder<LabRequisition>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', '!=', LabRequisitionStatus::Cancelled);
    }

    public function isCancelled(): bool
    {
        return $this->status === LabRequisitionStatus::Cancelled;
    }

    /**
     * @return array<int, string>
     */
    public function testLabels(): array
    {
        return $this->labPartner->testLabels($this->tests);
    }

    public function markReported(): void
    {
        if ($this->status === LabRequisitionStatus::Ordered) {
            $this->forceFill(['status' => LabRequisitionStatus::Reported, 'reported_at' => now()])->save();
        }
    }

    public function cancel(?User $by): void
    {
        if ($this->status !== LabRequisitionStatus::Ordered) {
            throw new LogicException('Only requisitions still waiting for results can be cancelled.');
        }

        $this->forceFill([
            'status' => LabRequisitionStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $by?->id,
        ])->save();
    }

    protected static function booted(): void
    {
        static::saving(function (LabRequisition $requisition) {
            $partner = LabPartner::find($requisition->lab_partner_id);
            $tests = $requisition->tests;

            if ($partner === null || $tests === [] || array_diff($tests, $partner->testCodes()) !== []) {
                throw new InvalidArgumentException('A requisition must order tests the lab partner offers.');
            }
        });

        static::deleting(fn () => throw new MedicalRecordRetentionException(
            'Lab requisitions must be retained and cannot be deleted. Cancel them instead.'
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tests' => 'array',
            'instructions' => 'encrypted',
            'status' => LabRequisitionStatus::class,
            'reported_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
