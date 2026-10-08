<?php

namespace App\WhatsApp;

use App\Notifications\Messages\WhatsAppMessage;

/**
 * Sends WhatsApp messages through a provider. Bound in AppServiceProvider
 * from reminders.channels.whatsapp.provider.
 */
interface WhatsAppGateway
{
    /**
     * Send the message and return the provider's message id.
     *
     * @param  string  $to  an E.164 phone number, e.g. +14155550123
     *
     * @throws WhatsAppDeliveryException
     */
    public function send(string $to, WhatsAppMessage $message): string;
}
