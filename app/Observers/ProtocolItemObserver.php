<?php

namespace App\Observers;

use App\Enums\AuditEvent;
use App\Models\ProtocolItem;
use App\Services\AuditLogger;

/**
 * Audits protocol items being added, adjusted and stopped. Never records the
 * name, dose or instructions.
 */
class ProtocolItemObserver
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function created(ProtocolItem $item): void
    {
        $metadata = ['prescription_id' => $item->prescription_id];

        if ($item->supersedes_id !== null) {
            $this->audit->record(
                AuditEvent::ProtocolItemAdjusted,
                auditable: $item,
                metadata: [...$metadata, 'supersedes_id' => $item->supersedes_id],
            );

            return;
        }

        $this->audit->record(AuditEvent::ProtocolItemAdded, auditable: $item, metadata: $metadata);
    }

    public function updated(ProtocolItem $item): void
    {
        // An adjusted item is stopped as part of the adjustment, already audited.
        if ($item->wasChanged('discontinued_at') && ! $item->successor()->exists()) {
            $this->audit->record(
                AuditEvent::ProtocolItemDiscontinued,
                auditable: $item,
                metadata: ['prescription_id' => $item->prescription_id],
            );
        }
    }
}
