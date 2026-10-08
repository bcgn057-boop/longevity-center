<?php

namespace App\Reminders;

use App\Enums\NotificationChannel;
use App\WhatsApp\PhoneMask;

/**
 * Masks an email address or phone number for display, so pages show which
 * address consent applies to without revealing it in full.
 */
final class AddressMask
{
    public static function for(NotificationChannel $channel, string $destination): string
    {
        return $channel === NotificationChannel::Mail
            ? self::email($destination)
            : PhoneMask::mask($destination);
    }

    public static function email(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', trim($email), 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }
}
