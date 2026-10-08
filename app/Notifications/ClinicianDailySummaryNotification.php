<?php

namespace App\Notifications;

use App\Enums\ReminderSkipReason;
use App\Models\ReminderDelivery;

/**
 * Tells a clinician how many appointments they have the next day.
 *
 * In-app only, and holding just the date and a count: never patient names.
 * The clinician follows it to the schedule, where access is checked.
 */
class ClinicianDailySummaryNotification extends TrackedNotification
{
    public const KIND = 'clinician_daily_summary';

    public function __construct(
        ReminderDelivery $delivery,
        public string $date,
        public int $appointments,
    ) {
        parent::__construct($delivery);
    }

    protected function skipReason(object $notifiable, ReminderDelivery $delivery): ?ReminderSkipReason
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'date' => $this->date,
            'appointments' => $this->appointments,
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'clinician.daily_summary';
    }
}
