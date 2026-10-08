<?php

namespace App\Notifications;

use App\Enums\ReminderSkipReason;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\ReminderDelivery;
use App\Notifications\Messages\WhatsAppMessage;
use App\Reminders\ReminderEligibility;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Reminds a patient of an upcoming appointment on one channel.
 *
 * Nothing clinical is ever included:
 *  - email: the clinic, date and time, and a portal link;
 *  - in-app: ids only, the wording is built when the inbox is shown;
 *  - WhatsApp: a fixed template with no variables.
 */
class AppointmentReminderNotification extends TrackedNotification
{
    public const KIND = 'appointment_24h';

    public function __construct(
        public Appointment $appointment,
        ReminderDelivery $delivery,
    ) {
        parent::__construct($delivery);
    }

    /**
     * The appointment may have been cancelled or moved, or consent
     * withdrawn, since the reminder was queued.
     */
    protected function skipReason(object $notifiable, ReminderDelivery $delivery): ?ReminderSkipReason
    {
        $appointment = $this->appointment->fresh();

        if ($appointment === null) {
            return ReminderSkipReason::SubjectUnavailable;
        }

        $eligibility = app(ReminderEligibility::class);

        return $eligibility->forAppointment($appointment, $delivery)
            ?? ($notifiable instanceof Patient ? $eligibility->forPatient($notifiable, $delivery->channel) : null);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $timezone = (string) config('clinic.timezone');
        $start = $this->appointment->start_time->copy()->setTimezone($timezone);

        $message = (new MailMessage)
            ->subject(__('Appointment reminder'))
            ->greeting(__('Hello,'))
            ->line(__('This is a reminder of your appointment at :clinic on :date at :time (:timezone).', [
                'clinic' => config('clinic.name'),
                'date' => $start->format('l j F Y'),
                'time' => $start->format('H:i'),
                'timezone' => $timezone,
            ]));

        if ($notifiable instanceof Patient && $notifiable->user_id !== null) {
            $message->action(__('View in the patient portal'), route('my-appointments.index'))
                ->line(__('Sign in to see the details or to cancel.'));
        } else {
            $message->line(__('To change or cancel, please contact the clinic.'));
        }

        return $message->salutation(__('Please do not reply to this email.'));
    }

    /**
     * Ids only: the message is worded when the inbox is shown, after access checks.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'appointment_id' => $this->appointment->id,
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'appointment.reminder';
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        return WhatsAppMessage::reminder();
    }
}
