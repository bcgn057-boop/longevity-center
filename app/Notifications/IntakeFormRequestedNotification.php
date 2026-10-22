<?php

namespace App\Notifications;

use App\Enums\ReminderSkipReason;
use App\Models\Patient;
use App\Models\PatientIntakeForm;
use App\Models\ReminderDelivery;
use App\Notifications\Messages\WhatsAppMessage;
use App\Reminders\ReminderEligibility;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Asks a patient to fill in their health questionnaire.
 *
 * Generic on every channel: the email carries the secure link, which only
 * opens once the patient signs in to their own account. WhatsApp uses the
 * generic "new message" template.
 */
class IntakeFormRequestedNotification extends TrackedNotification
{
    public const KIND = 'intake_form_requested';

    public function __construct(
        public PatientIntakeForm $form,
        public string $token,
        ReminderDelivery $delivery,
    ) {
        parent::__construct($delivery);
    }

    protected function skipReason(object $notifiable, ReminderDelivery $delivery): ?ReminderSkipReason
    {
        if (! ($this->form->fresh()?->isWithPatient() ?? false)) {
            return ReminderSkipReason::SubjectUnavailable;
        }

        return $notifiable instanceof Patient
            ? app(ReminderEligibility::class)->forPatient($notifiable, $delivery->channel, portalOnly: true)
            : null;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Please complete your health questionnaire'))
            ->greeting(__('Hello,'))
            ->line(__(':clinic has asked you to complete a health questionnaire before your next visit.', ['clinic' => config('clinic.name')]))
            ->action(__('Open the questionnaire'), route('intake.open', $this->token))
            ->line(__('You will be asked to sign in to your account. The link works for :days days.', ['days' => PatientIntakeForm::LINK_DAYS]))
            ->salutation(__('Please do not reply to this email.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['intake_form_id' => $this->form->id];
    }

    public function databaseType(object $notifiable): string
    {
        return 'intake.requested';
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        return WhatsAppMessage::careReminder();
    }
}
