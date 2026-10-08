<?php

namespace App\Notifications;

use App\Enums\ReminderSkipReason;
use App\Models\LabReport;
use App\Models\Patient;
use App\Models\ReminderDelivery;
use App\Notifications\Messages\WhatsAppMessage;
use App\Reminders\ReminderEligibility;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells a patient that a lab report has been released to them.
 *
 * Generic on every channel: no values, biomarkers or dates leave the
 * portal. The in-app record holds the report's id only.
 */
class LabResultsReadyNotification extends TrackedNotification
{
    public const KIND = 'lab_results_released';

    public function __construct(
        public LabReport $report,
        ReminderDelivery $delivery,
    ) {
        parent::__construct($delivery);
    }

    protected function skipReason(object $notifiable, ReminderDelivery $delivery): ?ReminderSkipReason
    {
        if (! ($this->report->fresh()?->isReleased() ?? false)) {
            return ReminderSkipReason::SubjectUnavailable;
        }

        return $notifiable instanceof Patient
            ? app(ReminderEligibility::class)->forPatient($notifiable, $delivery->channel, portalOnly: true)
            : null;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('New lab results are available'))
            ->greeting(__('Hello,'))
            ->line(__('New lab results from :clinic are available.', ['clinic' => config('clinic.name')]))
            ->action(__('Sign in to view them'), route('my-results.index'))
            ->line(__('If you have questions about your results, please talk to your clinician.'))
            ->salutation(__('Please do not reply to this email.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'lab_report_id' => $this->report->id,
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'lab.results_released';
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        return WhatsAppMessage::labResults();
    }
}
