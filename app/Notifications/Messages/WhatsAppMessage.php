<?php

namespace App\Notifications\Messages;

/**
 * A WhatsApp template message.
 *
 * Deliberately has no parameters: reminder templates are fixed text plus a
 * link to the portal, so nothing about the patient leaves the clinic.
 */
final readonly class WhatsAppMessage
{
    public function __construct(
        public string $template,
        public string $language,
    ) {}

    /**
     * The reminder template configured in config/reminders.php.
     */
    public static function reminder(): self
    {
        return new self(
            (string) config('reminders.channels.whatsapp.template'),
            (string) config('reminders.channels.whatsapp.language'),
        );
    }
}
