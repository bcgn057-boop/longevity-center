<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\ReminderSkipReason;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * One reminder to one recipient on one channel: the dispatch log.
 *
 * Holds ids and short codes only, never an address, message text or a
 * provider's response body.
 *
 * @property int $id
 * @property string $remindable_type
 * @property int $remindable_id
 * @property string $recipient_type
 * @property int $recipient_id
 * @property NotificationChannel $channel
 * @property string $kind
 * @property Carbon $scheduled_for
 * @property DeliveryStatus $status
 * @property string|null $reason_code
 * @property string|null $provider_message_id
 * @property int $attempts
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReminderDelivery extends Model
{
    /**
     * Claim the log row for a reminder, or return null if it already exists.
     * The unique key makes this the guard against sending anything twice.
     */
    public static function claim(
        Model $remindable,
        Model $recipient,
        NotificationChannel $channel,
        string $kind,
        CarbonInterface $scheduledFor,
    ): ?self {
        try {
            return self::forceCreate([
                'remindable_type' => $remindable->getMorphClass(),
                'remindable_id' => $remindable->getKey(),
                'recipient_type' => $recipient->getMorphClass(),
                'recipient_id' => $recipient->getKey(),
                'channel' => $channel,
                'kind' => $kind,
                'scheduled_for' => $scheduledFor,
                'status' => DeliveryStatus::Queued,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * Get what the reminder is about, e.g. an appointment.
     *
     * @return MorphTo<Model, $this>
     */
    public function remindable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get who the reminder is for.
     *
     * @return MorphTo<Model, $this>
     */
    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    public function markSent(?string $providerMessageId = null): void
    {
        $this->forceFill([
            'status' => DeliveryStatus::Sent,
            'reason_code' => null,
            'provider_message_id' => $providerMessageId,
            'sent_at' => now(),
        ])->save();
    }

    public function markSkipped(ReminderSkipReason $reason): void
    {
        $this->forceFill([
            'status' => DeliveryStatus::Skipped,
            'reason_code' => $reason->value,
        ])->save();
    }

    public function markFailed(string $code): void
    {
        $this->forceFill([
            'status' => DeliveryStatus::Failed,
            'reason_code' => mb_substr($code, 0, 64),
        ])->save();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'scheduled_for' => 'datetime',
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }
}
