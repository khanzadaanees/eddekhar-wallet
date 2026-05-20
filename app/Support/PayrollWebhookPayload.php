<?php

namespace App\Support;

class PayrollWebhookPayload
{
    /**
     * Extract event-specific data from a signed webhook envelope.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public static function eventData(array $envelope): array
    {
        return $envelope['payload'] ?? $envelope['data'] ?? $envelope;
    }
}
