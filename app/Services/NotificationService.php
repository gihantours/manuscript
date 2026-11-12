<?php

namespace App\Services;

use App\Exceptions\PermanentExternalException;
use App\Exceptions\RateLimitException;
use App\Exceptions\TransientExternalException;
use Illuminate\Support\Facades\Http;

class NotificationService
{
    /**
     * @return string The provider status: "sent" or "duplicate_ignored".
     */
    public function send(string $to, string $subject, string $body, string $idempotencyKey): string
    {
        $response = Http::baseUrl((string) config('services.notification.base_url'))
            ->acceptJson()
            ->connectTimeout((int) config('services.notification.connect_timeout', 3))
            ->timeout((int) config('services.notification.timeout', 10))
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/notify', [
                'to' => $to,
                'subject' => $subject,
                'body' => $body,
            ]);

        if ($response->status() === 429) {
            throw new RateLimitException((int) $response->header('Retry-After', '2'));
        }

        if ($response->serverError()) {
            throw new TransientExternalException('Notification service returned '.$response->status());
        }

        if ($response->clientError()) {
            throw new PermanentExternalException('Notification service rejected the request: '.$response->status());
        }

        return (string) $response->json('status', 'sent');
    }
}
