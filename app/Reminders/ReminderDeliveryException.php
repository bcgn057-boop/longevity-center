<?php

namespace App\Reminders;

use RuntimeException;

/**
 * A reminder could not be delivered.
 *
 * The message is a short code such as "provider_http_429" or
 * "mail_transport_error". It is what reaches the delivery log, laravel.log
 * and failed_jobs, so it must never include an address or a provider's
 * response. Never chain the original exception: its message may.
 */
class ReminderDeliveryException extends RuntimeException
{
    public function code(): string
    {
        return $this->getMessage();
    }
}
