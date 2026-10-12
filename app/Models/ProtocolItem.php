<?php

namespace App\Models;

use App\Enums\ProtocolFrequency;
use App\Enums\ProtocolItemType;
use App\Exceptions\MedicalRecordRetentionException;
use App\Observers\ProtocolItemObserver;
use Database\Factories\ProtocolItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * One intervention on a prescription. The name, dose and instructions are
 * encrypted.
 *
 * Items are never edited or deleted. An adjustment is a new item that points
 * to the one it replaces, and the old one is discontinued, so the history of
 * what the patient was asked to do is kept.
 *
 * @property int $id
 * @property int $prescription_id
 * @property ProtocolItemType $type
 * @property string $name
 * @property string|null $dosage
 * @property string|null $instructions
 * @property ProtocolFrequency $frequency
 * @property int $sort_order
 * @property int|null $supersedes_id
 * @property string|null $adjustment_reason
 * @property int|null $created_by
 * @property Carbon|null $discontinued_at
 * @property int|null $discontinued_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Prescription $prescription
 * @property-read ProtocolItem|null $supersedes
 * @property-read ProtocolItem|null $successor
 * @property-read User|null $creator
 * @property-read Collection<int, ProtocolAdherence> $adherences
 */
#[ObservedBy(ProtocolItemObserver::class)]
#[Fillable(['type', 'name', 'dosage', 'instructions', 'frequency', 'sort_order'])]
#[Hidden(['name', 'dosage', 'instructions', 'adjustment_reason'])]
class ProtocolItem extends Model
{
    /** @use HasFactory<ProtocolItemFactory> */
    use HasFactory;

    /**
     * The only columns that may change after an item is created.
     *
     * @var array<int, string>
     */
    private const DISCONTINUE_FIELDS = ['discontinued_at', 'discontinued_by', 'updated_at'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
    ];

    /**
     * @return BelongsTo<Prescription, $this>
     */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The item this one adjusts, if any.
     *
     * @return BelongsTo<ProtocolItem, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * The adjustment that replaced this item, if any.
     *
     * @return HasOne<ProtocolItem, $this>
     */
    public function successor(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_id');
    }

    /**
     * The patient's daily record against this item.
     *
     * @return HasMany<ProtocolAdherence, $this>
     */
    public function adherences(): HasMany
    {
        return $this->hasMany(ProtocolAdherence::class);
    }

    /**
     * Items still to be followed: not adjusted or stopped.
     *
     * @param  Builder<ProtocolItem>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('discontinued_at');
    }

    public function isDiscontinued(): bool
    {
        return $this->discontinued_at !== null;
    }

    /**
     * Whether the patient was asked to follow this item on the given day
     * (Y-m-d, in the clinic's timezone): the item existed and was not yet
     * stopped, and the day falls within the prescription's dates.
     */
    public function isScheduledOn(string $date): bool
    {
        $timezone = (string) config('clinic.timezone');
        $prescription = $this->prescription;

        return $this->created_at !== null
            && $this->created_at->setTimezone($timezone)->toDateString() <= $date
            && ($this->discontinued_at === null || $this->discontinued_at->setTimezone($timezone)->toDateString() > $date)
            && $prescription->starts_on->toDateString() <= $date
            && ($prescription->ends_on === null || $prescription->ends_on->toDateString() >= $date);
    }

    /**
     * Record whether the patient followed the item on a day, replacing what
     * they recorded before. Unticking keeps the row and its note.
     */
    public function recordAdherence(string $date, bool $taken, ?string $notes): ProtocolAdherence
    {
        if (! $this->isScheduledOn($date)) {
            throw new LogicException('This item was not part of the protocol on that day.');
        }

        $adherence = $this->adherences()->whereDate('scheduled_date', $date)->first() ?? new ProtocolAdherence;
        $adherence->forceFill([
            'protocol_item_id' => $this->id,
            'patient_id' => $this->prescription->patient_id,
            'scheduled_date' => $date,
            'notes' => filled($notes) ? trim((string) $notes) : null,
            // Ticking again keeps the time it was first ticked.
            'taken_at' => $taken ? ($adherence->taken ? $adherence->taken_at : now()) : null,
            'taken' => $taken,
        ])->save();

        return $adherence;
    }

    /**
     * Replace the item with a changed copy, e.g. a new dose. Attributes not
     * given are carried over.
     *
     * @param  array<string, mixed>  $attributes
     * @param  string  $reason  why it was adjusted, e.g. "dose increased after labs"
     */
    public function adjust(array $attributes, string $reason, ?User $by): self
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('An adjustment needs a reason.');
        }

        if ($this->isDiscontinued()) {
            throw new LogicException('This item is no longer current. Adjust the latest version instead.');
        }

        return DB::transaction(function () use ($attributes, $reason, $by) {
            $adjusted = new self([...$this->only($this->getFillable()), ...$attributes]);
            $adjusted->prescription_id = $this->prescription_id;
            $adjusted->supersedes_id = $this->id;
            $adjusted->adjustment_reason = trim($reason);
            $adjusted->created_by = $by?->id;
            $adjusted->save();

            $this->discontinue($by);

            return $adjusted;
        });
    }

    /**
     * Stop the item. Its history and adherence are kept.
     */
    public function discontinue(?User $by): void
    {
        $this->forceFill(['discontinued_at' => now(), 'discontinued_by' => $by?->id])->save();
    }

    protected static function booted(): void
    {
        static::creating(function (ProtocolItem $item) {
            if (! Prescription::find($item->prescription_id)?->isActive()) {
                throw new LogicException('Items can only be added to an active prescription.');
            }
        });

        static::updating(function (ProtocolItem $item) {
            if ($item->getRawOriginal('discontinued_at') !== null) {
                throw new LogicException('A discontinued item cannot be changed.');
            }

            if (array_diff(array_keys($item->getDirty()), self::DISCONTINUE_FIELDS) !== []) {
                throw new LogicException('Protocol items cannot be changed. Adjust the item instead.');
            }
        });

        static::deleting(fn () => throw new MedicalRecordRetentionException(
            'Protocol items must be retained and cannot be deleted. Discontinue them instead.'
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProtocolItemType::class,
            'name' => 'encrypted',
            'dosage' => 'encrypted',
            'instructions' => 'encrypted',
            'frequency' => ProtocolFrequency::class,
            'sort_order' => 'integer',
            'adjustment_reason' => 'encrypted',
            'discontinued_at' => 'datetime',
        ];
    }
}
