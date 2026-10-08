<?php

namespace App\Reminders;

use App\Enums\AuditEvent;
use App\Enums\NotificationChannel;
use App\Enums\ReminderSkipReason;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Records and checks consent to email and WhatsApp reminders.
 *
 * Consent is bound to the address it was given for, and every grant and
 * withdrawal is audited with the channel and wording version, never the
 * address itself.
 */
class NotificationConsent
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Record consent for the recipient's current address on the channel.
     *
     * @throws InvalidArgumentException when the channel needs no consent or there is no address
     */
    public function grant(Model&ReceivesReminders $recipient, NotificationChannel $channel, string $source, ?User $recordedBy): NotificationPreference
    {
        $this->ensureConsentable($channel);

        $destination = $recipient->reminderDestination($channel)
            ?? throw new InvalidArgumentException("There is no address to send {$channel->label()} reminders to.");

        $preference = $this->preference($recipient, $channel) ?? $this->newPreference($recipient, $channel);

        $preference->forceFill([
            'destination_hash' => DestinationFingerprint::of($channel, $destination),
            'consented_at' => now(),
            'withdrawn_at' => null,
            'consent_version' => (int) config('reminders.consent_version'),
            'source' => $source,
            'recorded_by' => $recordedBy?->id,
        ])->save();

        $this->audit->record(
            AuditEvent::NotificationConsentGranted,
            actor: $recordedBy,
            auditable: $recipient,
            metadata: [
                'channel' => $channel->value,
                'consent_version' => $preference->consent_version,
                'source' => $source,
            ],
        );

        return $preference;
    }

    /**
     * Withdraw consent for the channel. Does nothing if none is given.
     */
    public function withdraw(Model&ReceivesReminders $recipient, NotificationChannel $channel, string $source, ?User $recordedBy): void
    {
        $this->ensureConsentable($channel);

        $preference = $this->preference($recipient, $channel);

        if ($preference === null || ! $preference->isActive()) {
            return;
        }

        $preference->forceFill([
            'withdrawn_at' => now(),
            'source' => $source,
            'recorded_by' => $recordedBy?->id,
        ])->save();

        $this->audit->record(
            AuditEvent::NotificationConsentWithdrawn,
            actor: $recordedBy,
            auditable: $recipient,
            metadata: ['channel' => $channel->value, 'source' => $source],
        );
    }

    /**
     * Check whether reminders may go to the recipient's current address on
     * the channel. Returns why not, or null when they may.
     */
    public function check(Model&ReceivesReminders $recipient, NotificationChannel $channel): ?ReminderSkipReason
    {
        $destination = $recipient->reminderDestination($channel);

        if ($destination === null) {
            return ReminderSkipReason::NoDestination;
        }

        if (! $channel->requiresConsent()) {
            return null;
        }

        $preference = $this->preference($recipient, $channel);

        if ($preference === null || ! $preference->isActive() || $preference->destination_hash === null) {
            return ReminderSkipReason::NoConsent;
        }

        if (! DestinationFingerprint::matches($preference->destination_hash, $channel, $destination)) {
            return ReminderSkipReason::DestinationChanged;
        }

        return null;
    }

    private function preference(Model $recipient, NotificationChannel $channel): ?NotificationPreference
    {
        return NotificationPreference::query()
            ->where('notifiable_type', $recipient->getMorphClass())
            ->where('notifiable_id', $recipient->getKey())
            ->where('channel', $channel)
            ->first();
    }

    private function newPreference(Model $recipient, NotificationChannel $channel): NotificationPreference
    {
        $preference = new NotificationPreference;
        $preference->notifiable_type = $recipient->getMorphClass();
        $preference->notifiable_id = (int) $recipient->getKey();
        $preference->channel = $channel;

        return $preference;
    }

    private function ensureConsentable(NotificationChannel $channel): void
    {
        if (! $channel->requiresConsent()) {
            throw new InvalidArgumentException("{$channel->label()} reminders do not need consent.");
        }
    }
}
