<?php

namespace App\Notifications;

use App\Enums\CareReminderStatus;
use App\Enums\ReminderSkipReason;
use App\Models\CareReminder;
use App\Models\Patient;
use App\Models\ReminderDelivery;
use App\Notifications\Messages\WhatsAppMessage;
use App\Reminders\ReminderEligibility;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Delivers a staff-written care reminder on one channel.
 *
 * The reminder's text may be clinical, so it never leaves the portal: email
 * and WhatsApp only say there is a new message, and the in-app record holds
 * the reminder's id, worded when the inbox is shown.
 */
class CareReminderNotification extends TrackedNotification
{
    public const KIND = 'care_reminder';

    public function __construct(
        public CareReminder $reminder,
        ReminderDelivery $delivery,
    ) {
        parent::__construct($delivery);
    }

    protected function skipReason(object $notifiable, ReminderDelivery $delivery): ?ReminderSkipReason
    {
        if ($this->reminder->fresh()?->status !== CareReminderStatus::Sent) {
            return ReminderSkipReason::SubjectUnavailable;
        }

        return $notifiable instanceof Patient
            ? app(ReminderEligibility::class)->forPatient($notifiable, $delivery->channel, portalOnly: true)
            : null;
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
}
