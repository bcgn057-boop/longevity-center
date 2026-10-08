<?php

namespace App\Enums;

/**
 * Where a reminder delivery stands.
 */
enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
