<?php

namespace App\Notifications;

use App\Enums\DeliveryStatus;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\ReminderDelivery;
use App\Notifications\Messages\WhatsAppMessage;
use App\Reminders\ReminderEligibility;
use App\WhatsApp\WhatsAppDeliveryException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Reminds a patient of an upcoming appointment on one channel.
 *
 * Each instance belongs to one row in the delivery log and is sent on that
 * row's channel only. Nothing clinical is ever included:
 *  - email: the clinic, date and time, and a portal link;
 *  - in-app: ids only, the wording is built when the inbox is shown;
 *  - WhatsApp: a fixed template with no variables.
 */
class AppointmentReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const KIND = 'appointment_24h';

    public int $tries;

    public function __construct(
        public Appointment $appointment,
        public ReminderDelivery $delivery,
    ) {
        $this->tries = count($this->backoff()) + 1;

        // Never send for a booking whose transaction is rolled back.
        $this->afterCommit();
    }

    /**
     * Seconds to wait before each retry.
     *
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

    /**
     * Re-check just before sending: the appointment may have been cancelled
     * or moved, or consent withdrawn, since the reminder was queued.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $delivery = $this->delivery->fresh();
        $appointment = $this->appointment->fresh();

        if ($delivery === null || $appointment === null || $delivery->status !== DeliveryStatus::Queued) {
            return false;
        }

        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();

        $eligibility = app(ReminderEligibility::class);
        $reason = $eligibility->forAppointment($appointment, $delivery)
            ?? ($notifiable instanceof Patient ? $eligibility->forPatient($notifiable, $delivery->channel) : null);

        if ($reason !== null) {
            $delivery->markSkipped($reason);

            return false;
        }

        return true;
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

    /**
     * Called once all retries are used up. Records a short code only: an
     * exception message may contain the recipient's address.
     */
    public function failed(Throwable $exception): void
    {
        $this->delivery->fresh()?->markFailed(match (true) {
            $exception instanceof WhatsAppDeliveryException => $exception->code(),
            default => 'error_'.class_basename($exception),
        });
    }
}
