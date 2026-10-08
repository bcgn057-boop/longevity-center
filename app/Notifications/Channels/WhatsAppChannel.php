<?php

namespace App\Notifications\Channels;

use App\Notifications\Messages\WhatsAppMessage;
use App\WhatsApp\WhatsAppGateway;
use Illuminate\Notifications\Notification;
use LogicException;

/**
 * Delivers notifications as WhatsApp template messages.
 *
 * A notification sent through this channel implements toWhatsApp(), and its
 * notifiable implements routeNotificationForWhatsApp() returning an E.164
 * number. The provider's message id is returned, so NotificationSent
 * listeners receive it as the response.
 */
class WhatsAppChannel
{
    public function __construct(private readonly WhatsAppGateway $gateway) {}

    public function send(object $notifiable, Notification $notification): ?string
    {
        if (! method_exists($notification, 'toWhatsApp')) {
            throw new LogicException($notification::class.' cannot be sent by WhatsApp.');
        }

        $to = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor('whatsapp', $notification)
            : null;

        if (! is_string($to) || $to === '') {
            return null;
        }

        $message = $notification->toWhatsApp($notifiable);

        if (! $message instanceof WhatsAppMessage) {
            throw new LogicException('toWhatsApp() must return a '.WhatsAppMessage::class.'.');
        }

        return $this->gateway->send($to, $message);
    }
}
