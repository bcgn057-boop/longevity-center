<?php

namespace App\WhatsApp;

use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Sends WhatsApp template messages through Meta's Cloud API.
 *
 * Only the template name, its language and the phone number are sent.
 */
class MetaCloudWhatsAppGateway implements WhatsAppGateway
{
    public function __construct(
        private readonly string $token,
        private readonly string $phoneNumberId,
        private readonly string $apiVersion,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {}

    /**
     * Build the gateway from config/services.php.
     */
    public static function fromConfig(): self
    {
        $config = (array) config('services.meta_whatsapp');

        if (blank($config['token'] ?? null) || blank($config['phone_number_id'] ?? null)) {
            throw new WhatsAppDeliveryException('meta_not_configured');
        }

        return new self(
            (string) $config['token'],
            (string) $config['phone_number_id'],
            (string) ($config['api_version'] ?? 'v23.0'),
            rtrim((string) ($config['base_url'] ?? 'https://graph.facebook.com'), '/'),
            (int) ($config['timeout'] ?? 10),
        );
    }

    public function send(string $to, WhatsAppMessage $message): string
    {
        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->timeout($this->timeout)
                ->post("{$this->baseUrl}/{$this->apiVersion}/{$this->phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => ltrim($to, '+'),
                    'type' => 'template',
                    'template' => [
                        'name' => $message->template,
                        'language' => ['code' => $message->language],
                    ],
                ]);
        } catch (ConnectionException) {
            throw new WhatsAppDeliveryException('provider_unreachable');
        }

        if ($response->failed()) {
            $code = $response->json('error.code');

            throw new WhatsAppDeliveryException(
                is_int($code) ? "meta_error_{$code}" : "provider_http_{$response->status()}",
            );
        }

        $id = $response->json('messages.0.id');

        if (! is_string($id) || $id === '') {
            throw new WhatsAppDeliveryException('provider_bad_response');
        }

        return $id;
    }
}
