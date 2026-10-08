<?php

namespace App\Reminders;

use App\Notifications\AppointmentReminderNotification;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Marks a reminder's delivery log row as sent, keeping the provider's
 * message id when the channel returns one (WhatsApp).
 */
class RecordReminderSent
{
    public function handle(NotificationSent $event): void
    {
        if (! $event->notification instanceof AppointmentReminderNotification) {
            return;
        }

        $event->notification->delivery->fresh()?->markSent(
            is_string($event->response) ? $event->response : null,
        );
    }
}
