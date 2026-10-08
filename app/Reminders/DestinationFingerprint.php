<?php

namespace App\Reminders;

use App\Enums\NotificationChannel;

/**
 * A keyed one-way hash of an email address or phone number.
 *
 * Consent records store this instead of the address, so they can show that
 * consent was given for the current address without holding it. Keyed with
 * APP_KEY: rotating the key means patients must give consent again.
 */
class DestinationFingerprint
{
    public static function of(NotificationChannel $channel, string $destination): string
    {
        return hash_hmac(
            'sha256',
            $channel->value.'|'.self::normalise($channel, $destination),
            (string) config('app.key'),
        );
    }

    /**
     * Determine if the fingerprint was taken from the given address.
     */
    public static function matches(string $fingerprint, NotificationChannel $channel, string $destination): bool
    {
        return hash_equals($fingerprint, self::of($channel, $destination));
    }

    private static function normalise(NotificationChannel $channel, string $destination): string
    {
        return match ($channel) {
            NotificationChannel::Mail => mb_strtolower(trim($destination)),
            default => preg_replace('/[^\d+]/', '', $destination) ?? $destination,
        };
    }
}
