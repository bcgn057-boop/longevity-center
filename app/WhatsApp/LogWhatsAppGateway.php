<?php

namespace App\WhatsApp;

use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Development provider: sends nothing and logs only the template name and a
 * masked number.
 */
class LogWhatsAppGateway implements WhatsAppGateway
{
    public function send(string $to, WhatsAppMessage $message): string
    {
        $id = 'log-'.Str::uuid()->toString();

        Log::info('WhatsApp message not sent (log provider).', [
            'template' => $message->template,
            'language' => $message->language,
            'to' => PhoneMask::mask($to),
            'message_id' => $id,
        ]);

        return $id;
    }
}
