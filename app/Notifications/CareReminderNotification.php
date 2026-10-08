<?php

namespace App\Notifications;

use App\Enums\CareReminderStatus;
use App\Enums\DeliveryStatus;
use App\Models\CareReminder;
use App\Models\Patient;
use App\Models\ReminderDelivery;
use App\Notifications\Messages\WhatsAppMessage;
use App\Reminders\ReminderDeliveryException;
use App\Reminders\ReminderEligibility;
use App\Reminders\TracksDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Delivers a staff-written care reminder on one channel.
 *
 * The reminder's text may be clinical, so it never leaves the portal: email
 * and WhatsApp only say there is a new message, and the in-app record holds
 * the reminder's id, worded when the inbox is shown.
 */
class CareReminderNotification extends Notification implements ShouldQueue, TracksDelivery
{
    use Queueable;

    public const KIND = 'care_reminder';

    public int $tries;

    public function __construct(
        public CareReminder $reminder,
        public ReminderDelivery $delivery,
    ) {
        $this->tries = count($this->backoff()) + 1;
        $this->afterCommit();
    }

    public function delivery(): ReminderDelivery
    {
        return $this->delivery;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return array_map(intval(...), (array) config('reminders.backoff'));
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [$this->delivery->channel->driver()];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $delivery = $this->delivery->fresh();
        $reminder = $this->reminder->fresh();

        if ($delivery === null || $reminder === null || $delivery->status !== DeliveryStatus::Queued) {
            return false;
        }

        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();

        $reason = $notifiable instanceof Patient
            ? app(ReminderEligibility::class)->forPatient($notifiable, $delivery->channel, portalOnly: true)
            : null;

        if ($reason !== null) {
            $delivery->markSkipped($reason);

            return false;
        }

        return $reminder->status === CareReminderStatus::Sent;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('New message from :clinic', ['clinic' => config('clinic.name')]))
            ->greeting(__('Hello,'))
            ->line(__('You have a new message from :clinic.', ['clinic' => config('clinic.name')]))
            ->action(__('Sign in to read it'), route('notifications.index'))
            ->salutation(__('Please do not reply to this email.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'care_reminder_id' => $this->reminder->id,
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'care.reminder';
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        return WhatsAppMessage::careReminder();
    }

    public function failed(Throwable $exception): void
    {
        $this->delivery->fresh()?->markFailed(
            $exception instanceof ReminderDeliveryException ? $exception->code() : 'error_'.class_basename($exception),
        );
    }
}
