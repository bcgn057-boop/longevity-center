<?php

namespace App\Models;

use App\Enums\PrescriptionStatus;
use App\Exceptions\MedicalRecordRetentionException;
use App\Observers\PrescriptionObserver;
use Database\Factories\PrescriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * A protocol a doctor issues to a patient: a set of interventions with the
 * doctor's notes. The title, notes and discontinuation reason are encrypted.
 *
 * A discontinued prescription is final. Prescriptions are never deleted.
 *
 * @property int $id
 * @property int $patient_id
 * @property string $title
 * @property string|null $notes
 * @property PrescriptionStatus $status
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property int|null $prescribed_by
 * @property Carbon|null $discontinued_at
 * @property int|null $discontinued_by
 * @property string|null $discontinued_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Patient $patient
 * @property-read User|null $prescriber
 */
#[ObservedBy(PrescriptionObserver::class)]
#[Fillable(['title', 'notes', 'starts_on', 'ends_on'])]
#[Hidden(['title', 'notes', 'discontinued_reason'])]
class Prescription extends Model
{
    /** @use HasFactory<PrescriptionFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => PrescriptionStatus::Active->value,
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
    public function prescriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescribed_by');
    }

    /**
     * Every item ever on the prescription, including adjusted and stopped ones.
     *
     * @return HasMany<ProtocolItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ProtocolItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The items the patient is currently asked to follow.
     *
     * @return HasMany<ProtocolItem, $this>
     */
    public function currentItems(): HasMany
    {
        return $this->items()->current();
    }

    public function isActive(): bool
    {
        return $this->status === PrescriptionStatus::Active;
    }

    /**
     * Whether the prescription's end date has passed. It stays active in the
     * records, but is shown as completed.
     */
    public function hasEnded(): bool
    {
        return $this->ends_on !== null && $this->ends_on->toDateString() < self::today();
    }

    /**
     * Today's date in the clinic's timezone (Y-m-d).
     */
    public static function today(): string
    {
        return now((string) config('clinic.timezone'))->toDateString();
    }

    /**
     * Active prescriptions whose dates cover the given day (today by default),
     * counted in the clinic's timezone.
     *
     * @param  Builder<Prescription>  $query
     */
    public function scopeInEffect(Builder $query, ?string $on = null): void
    {
        $on ??= self::today();

        $query->where('status', PrescriptionStatus::Active)
            ->whereDate('starts_on', '<=', $on)
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $on));
    }

    /**
     * Add an item to the prescription.
     *
     * @param  array<string, mixed>  $attributes  type, name, frequency, and optionally dosage, instructions, sort_order
     */
    public function addItem(array $attributes, ?User $by): ProtocolItem
    {
        $item = new ProtocolItem($attributes);
        $item->prescription_id = $this->id;
        $item->created_by = $by?->id;
        $item->save();

        return $item;
    }

    /**
     * Stop the prescription and every item still on it.
     *
     * @param  string  $reason  why it was stopped, e.g. "side effects"
     */
    public function discontinue(string $reason, ?User $by): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Discontinuing a prescription needs a reason.');
        }

        if (! $this->isActive()) {
            throw new LogicException('This prescription has already been discontinued.');
        }

        DB::transaction(function () use ($reason, $by) {
            $this->currentItems()->get()->each->discontinue($by);

            $this->forceFill([
                'status' => PrescriptionStatus::Discontinued,
                'discontinued_at' => now(),
                'discontinued_by' => $by?->id,
                'discontinued_reason' => trim($reason),
            ])->save();
        });
    }

    protected static function booted(): void
    {
        static::saving(function (Prescription $prescription) {
            if ($prescription->ends_on !== null && $prescription->ends_on->lt($prescription->starts_on)) {
                throw new InvalidArgumentException('A prescription cannot end before it starts.');
            }
        });

        static::updating(function (Prescription $prescription) {
            if (PrescriptionStatus::from($prescription->getRawOriginal('status')) === PrescriptionStatus::Discontinued) {
                throw new LogicException('A discontinued prescription cannot be changed. Issue a new one instead.');
            }
        });

        static::deleting(fn () => throw new MedicalRecordRetentionException(
            'Prescriptions must be retained and cannot be deleted. Discontinue them instead.'
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title' => 'encrypted',
            'notes' => 'encrypted',
            'status' => PrescriptionStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'discontinued_at' => 'datetime',
            'discontinued_reason' => 'encrypted',
        ];
    }
}
