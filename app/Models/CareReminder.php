<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\CareReminderStatus;
use App\Exceptions\MedicalRecordRetentionException;
use App\Observers\CareReminderObserver;
use Database\Factories\CareReminderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A staff-written reminder for a patient, sent when due. The title and
 * message are encrypted and only ever shown inside the patient portal.
 *
 * @property int $id
 * @property int $patient_id
 * @property string $title
 * @property string|null $message
 * @property Carbon $due_at
 * @property CareReminderStatus $status
 * @property Carbon|null $sent_at
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Patient $patient
 * @property-read User|null $creator
 */
#[ObservedBy(CareReminderObserver::class)]
#[Fillable(['title', 'message', 'due_at'])]
#[Hidden(['title', 'message'])]
class CareReminder extends Model
{
    /** @use HasFactory<CareReminderFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => CareReminderStatus::Scheduled->value,
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Record that the reminder has been handed to its channels.
     */
    public function markSent(): void
    {
        $this->forceFill(['status' => CareReminderStatus::Sent, 'sent_at' => now()])->save();
    }

    public function cancel(?User $by): void
    {
        $this->forceFill([
            'status' => CareReminderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $by?->id,
        ])->save();
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new MedicalRecordRetentionException(
            'Care reminders must be retained and cannot be deleted. Cancel them instead.'
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title' => 'encrypted',
            'message' => 'encrypted',
            'due_at' => UtcDateTime::class,
            'status' => CareReminderStatus::class,
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
