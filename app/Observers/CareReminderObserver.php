<?php

namespace App\Observers;

use App\Enums\AuditEvent;
use App\Enums\CareReminderStatus;
use App\Models\CareReminder;
use App\Services\AuditLogger;

/**
 * Audits care reminders being created and cancelled, never their text.
 */
class CareReminderObserver
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function created(CareReminder $reminder): void
    {
        $this->audit->record(
            AuditEvent::CareReminderCreated,
            auditable: $reminder,
            metadata: ['patient_id' => $reminder->patient_id, 'fields' => array_values(array_filter(
                ['title', 'message'],
                fn (string $field) => filled($reminder->{$field}),
            ))],
        );
    }

    public function updated(CareReminder $reminder): void
    {
        if ($reminder->wasChanged('status') && $reminder->status === CareReminderStatus::Cancelled) {
            $this->audit->record(AuditEvent::CareReminderCancelled, auditable: $reminder);
        }
    }
}
