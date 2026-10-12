<?php

namespace App\Models;

use App\Exceptions\MedicalRecordRetentionException;
use App\Observers\ProtocolAdherenceObserver;
use Database\Factories\ProtocolAdherenceFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Whether the patient followed one protocol item on one day, as they
 * reported it. The note is encrypted. Rows are kept, never deleted.
 *
 * @property int $id
 * @property int $protocol_item_id
 * @property int $patient_id
 * @property Carbon $scheduled_date
 * @property bool $taken
 * @property Carbon|null $taken_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProtocolItem $item
 * @property-read Patient $patient
 */
#[ObservedBy(ProtocolAdherenceObserver::class)]
#[Hidden(['notes'])]
class ProtocolAdherence extends Model
{
    /** @use HasFactory<ProtocolAdherenceFactory> */
    use HasFactory;

    /**
     * How many days back a patient may tick off, today included.
     */
    public const DAYS_TRACKED = 7;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'taken' => false,
    ];

    /**
     * @return BelongsTo<ProtocolItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ProtocolItem::class, 'protocol_item_id');
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    protected static function booted(): void
    {
        static::saving(function (ProtocolAdherence $adherence) {
            $patientId = ProtocolItem::query()
                ->join('prescriptions', 'prescriptions.id', '=', 'protocol_items.prescription_id')
                ->where('protocol_items.id', $adherence->protocol_item_id)
                ->value('prescriptions.patient_id');

            if ($patientId === null || (int) $patientId !== (int) $adherence->patient_id) {
                throw new LogicException('Adherence must belong to the patient the item was prescribed to.');
            }
        });

        static::deleting(fn () => throw new MedicalRecordRetentionException(
            'Adherence records must be retained and cannot be deleted.'
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'taken' => 'boolean',
            'taken_at' => 'datetime',
            'notes' => 'encrypted',
        ];
    }
}
