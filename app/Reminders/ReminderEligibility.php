<?php

namespace App\Reminders;

use App\Enums\AppointmentStatus;
use App\Enums\NotificationChannel;
use App\Enums\ReminderSkipReason;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\ReminderDelivery;

/**
 * Decides whether a reminder may go to a patient on a channel. Checked when
 * the reminder is queued and again just before it is sent.
 */
class ReminderEligibility
{
    public function __construct(private readonly NotificationConsent $consent) {}

    /**
     * Get why the patient may not be reminded on the channel, or null if they may.
     */
    public function forPatient(Patient $patient, NotificationChannel $channel): ?ReminderSkipReason
    {
        if (! $channel->enabled()) {
            return ReminderSkipReason::ChannelDisabled;
        }

        if ($patient->trashed()) {
            return ReminderSkipReason::PatientArchived;
        }

        // In-app messages need an inbox, and the WhatsApp message is only a
        // link to the portal.
        if ($channel !== NotificationChannel::Mail && $patient->user_id === null) {
            return ReminderSkipReason::NoPortalAccount;
        }

        return $this->consent->check($patient, $channel);
    }

    /**
     * Get why a queued appointment reminder is no longer due, or null if it still is.
     */
    public function forAppointment(Appointment $appointment, ReminderDelivery $delivery): ?ReminderSkipReason
    {
        if ($appointment->status !== AppointmentStatus::Scheduled) {
            return ReminderSkipReason::AppointmentNotScheduled;
        }

        if (! $appointment->start_time->equalTo($delivery->scheduled_for)) {
            return ReminderSkipReason::AppointmentMoved;
        }

        return null;
    }
}
