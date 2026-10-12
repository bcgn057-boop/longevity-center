<?php

namespace App\Observers;

use App\Enums\AuditEvent;
use App\Models\ProtocolAdherence;
use App\Services\AuditLogger;

/**
 * Audits a patient recording adherence, by field name only. Never records
 * whether the item was taken or the note.
 */
class ProtocolAdherenceObserver
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function saved(ProtocolAdherence $adherence): void
    {
        $changed = array_values(array_filter(
            ['taken', 'notes'],
            fn (string $field) => $adherence->wasRecentlyCreated ? filled($adherence->getAttributes()[$field] ?? null) : $adherence->wasChanged($field),
        ));

        if ($changed === []) {
            return;
        }

        $this->audit->record(AuditEvent::AdherenceRecorded, auditable: $adherence, metadata: [
            'protocol_item_id' => $adherence->protocol_item_id,
            'scheduled_date' => $adherence->scheduled_date->toDateString(),
            'fields' => $changed,
        ]);
    }
}
