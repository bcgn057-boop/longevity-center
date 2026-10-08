<?php

namespace App\Reminders;

use App\Enums\NotificationChannel;

/**
 * A recipient whose consent to email and WhatsApp reminders is recorded.
 */
interface ReceivesReminders
{
    /**
     * Get the address reminders on the channel go to (an email address or an
     * E.164 phone number), or null if there is none.
     */
    public function reminderDestination(NotificationChannel $channel): ?string;
}
