<?php

namespace App\Reminders;

use App\Enums\AppointmentStatus;
use App\Enums\CareReminderStatus;
use App\Enums\NotificationChannel;
use App\Models\Appointment;
use App\Models\CareReminder;
use App\Models\LabReport;
use App\Models\Patient;
use App\Models\PatientIntakeForm;
use App\Models\ReminderDelivery;
use App\Notifications\AppointmentReminderNotification;
use App\Notifications\CareReminderNotification;
use App\Notifications\IntakeFormRequestedNotification;
use App\Notifications\LabResultsReadyNotification;
use App\Notifications\TrackedNotification;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Queues reminders, one per enabled channel.
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

        return $this->send(
            $appointment,
            $appointment->patient,
            AppointmentReminderNotification::KIND,
            $appointment->start_time,
            portalOnly: false,
            make: fn (ReminderDelivery $delivery) => new AppointmentReminderNotification($appointment, $delivery),
        );
    }

    /**
     * Send a due care reminder on every enabled channel, then mark it sent.
     *
     * @return array<int, ReminderDelivery> the deliveries claimed by this call
     */
    public function sendCareReminder(CareReminder $reminder): array
    {
        if ($reminder->status !== CareReminderStatus::Scheduled) {
            return [];
        }

        // Marked first: the notifications only go out for a sent reminder,
        // and a staff member can no longer cancel it once it is on its way.
        $reminder->markSent();

        return $this->send(
            $reminder,
            $reminder->patient,
            CareReminderNotification::KIND,
            $reminder->due_at,
            portalOnly: true,
            make: fn (ReminderDelivery $delivery) => new CareReminderNotification($reminder, $delivery),
        );
    }

    /**
     * Tell the patient a released lab report is ready to view.
     *
     * @return array<int, ReminderDelivery> the deliveries claimed by this call
     */
    public function announceLabResults(LabReport $report): array
    {
        if (! $report->isReleased() || $report->reviewed_at === null) {
            return [];
        }

        return $this->send(
            $report,
            $report->patient,
            LabResultsReadyNotification::KIND,
            $report->reviewed_at,
            portalOnly: true,
            make: fn (ReminderDelivery $delivery) => new LabResultsReadyNotification($report, $delivery),
        );
    }

    /**
     * Ask the patient to fill in their questionnaire, with the link's token.
     *
     * @return array<int, ReminderDelivery>
     */
    public function announceIntakeForm(PatientIntakeForm $form, string $token): array
    {
        return $this->send(
            $form,
            $form->patient,
            IntakeFormRequestedNotification::KIND,
            $form->sent_at ?? now(),
            portalOnly: true,
            make: fn (ReminderDelivery $delivery) => new IntakeFormRequestedNotification($form, $token, $delivery),
        );
    }

    /**
     * Claim a delivery log row per enabled channel, skip channels the patient
     * may not be reached on, and queue the notification on the rest.
     *
     * @param  bool  $portalOnly  the message only says "sign in to view", so every channel needs a portal account
     * @param  Closure(ReminderDelivery): TrackedNotification  $make
     * @return array<int, ReminderDelivery>
     */
    private function send(Model $subject, Patient $patient, string $kind, CarbonInterface $scheduledFor, bool $portalOnly, Closure $make): array
    {
        $deliveries = [];

        foreach (NotificationChannel::enabledCases() as $channel) {
            $delivery = ReminderDelivery::claim($subject, $patient, $channel, $kind, $scheduledFor);

            if ($delivery === null) {
                continue;
            }

            $deliveries[] = $delivery;
            $reason = $this->eligibility->forPatient($patient, $channel, $portalOnly);

            if ($reason !== null) {
                $delivery->markSkipped($reason);

                continue;
            }

            try {
                $patient->notify($make($delivery));
            } catch (Throwable) {
                // Only reached when the queue runs jobs synchronously. The
                // notification's failed() hook has recorded the code; one
                // channel failing must not stop the others.
            }
        }

        return $deliveries;
    }
}
