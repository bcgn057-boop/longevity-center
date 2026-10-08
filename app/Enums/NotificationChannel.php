<?php

namespace App\Enums;

use App\Notifications\Channels\WhatsAppChannel;

/**
 * The channels reminders can be delivered through.
 */
enum NotificationChannel: string
{
    case Database = 'database';
    case Mail = 'mail';
    case WhatsApp = 'whatsapp';

    /**
     * Get the human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Database => 'In-app',
            self::Mail => 'Email',
            self::WhatsApp => 'WhatsApp',
        };
    }

    /**
     * Get the Laravel notification channel that delivers this channel.
     */
    public function driver(): string
    {
        return match ($this) {
            self::Database => 'database',
            self::Mail => 'mail',
            self::WhatsApp => WhatsAppChannel::class,
        };
    }

    /**
     * Determine if a patient must give recorded consent before this channel is used.
     * Email and WhatsApp leave the clinic's systems; in-app messages do not.
     */
    public function requiresConsent(): bool
    {
        return $this !== self::Database;
    }

    /**
     * Determine if the channel is switched on in config/reminders.php.
     */
    public function enabled(): bool
    {
        return (bool) config("reminders.channels.{$this->value}.enabled", false);
    }

    /**
     * The channels that are switched on.
     *
     * @return array<int, self>
     */
    public static function enabledCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $channel) => $channel->enabled()));
    }
}
