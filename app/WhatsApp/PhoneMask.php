<?php

namespace App\WhatsApp;

/**
 * Masks a phone number for logs, keeping only the last two digits.
 */
final class PhoneMask
{
    public static function mask(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return '+'.str_repeat('•', max(strlen($digits) - 2, 0)).substr($digits, -2);
    }
}
