<?php

namespace App\WhatsApp;

use App\Reminders\ReminderDeliveryException;

/**
 * A WhatsApp send failed, e.g. "provider_http_429" or "meta_error_131026".
 */
class WhatsAppDeliveryException extends ReminderDeliveryException {}
