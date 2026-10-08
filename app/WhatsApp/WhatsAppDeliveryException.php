<?php

namespace App\WhatsApp;

use RuntimeException;

/**
 * A WhatsApp send failed. The message is a short code such as
 * "provider_http_429" or "meta_error_131026", safe to store in the delivery
 * log: it never includes the phone number or the provider's response body.
 */
class WhatsAppDeliveryException extends RuntimeException
{
    public function code(): string
    {
        return $this->getMessage();
    }
}
