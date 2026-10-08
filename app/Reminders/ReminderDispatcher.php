<?php

namespace App\Reminders;

use App\Enums\AppointmentStatus;
use App\Enums\NotificationChannel;
use App\Models\Appointment;
use App\Models\ReminderDelivery;
use App\Notifications\AppointmentReminderNotification;
use Throwable;

/**
 * Queues an appointment's reminders, one per enabled channel.
 *
 * Each channel first claims its row in the delivery log. The unique key
 * means a second attempt for the same reminder finds the row taken and does
 * nothing, so running the dispatcher twice never sends twice.
 */
class ReminderDispatcher
{
    public function __construct(private readonly ReminderEligibility $eligibility) {}

    /**
     * @return array<int, ReminderDelivery> the deliveries claimed by this call
     */
    public function remindPatient(Appointment $appointment): array
    {
        if ($appointment->status !== AppointmentStatus::Scheduled) {
            return [];
        }

        $patient = $appointment->patient;
        $deliveries = [];

        foreach (NotificationChannel::enabledCases() as $channel) {
            $delivery = ReminderDelivery::claim(
                $appointment,
                $patient,
                $channel,
                AppointmentReminderNotification::KIND,
                $appointment->start_time,
            );

            if ($delivery === null) {
                continue;
            }

            $deliveries[] = $delivery;
            $reason = $this->eligibility->forPatient($patient, $channel);

            if ($reason !== null) {
                $delivery->markSkipped($reason);

                continue;
            }

            try {
                $patient->notify(new AppointmentReminderNotification($appointment, $delivery));
            } catch (Throwable) {
                // Only reached when the queue runs jobs synchronously. The
                // notification's failed() hook has recorded the code; one
                // channel failing must not stop the others.
            }
        }

        return $deliveries;
    }
}
