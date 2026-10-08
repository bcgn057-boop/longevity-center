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
     * The appointment reminder template configured in config/reminders.php.
     */
    public static function reminder(): self
    {
        return self::configured('template');
    }

    /**
     * The care reminder template configured in config/reminders.php.
     */
    public static function careReminder(): self
    {
        return self::configured('care_template');
    }

    private static function configured(string $key): self
    {
        return new self(
            (string) config("reminders.channels.whatsapp.{$key}"),
            (string) config('reminders.channels.whatsapp.language'),
        );
    }
}
