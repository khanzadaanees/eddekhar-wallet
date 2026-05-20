<?php

namespace App\Jobs;

use App\Support\InternalApplicationRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendBankWebhookCallbackJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $bankReferenceId,
        public string $status = 'confirmed',
    ) {}

    public function handle(): void
    {
        InternalApplicationRequest::postJson('/api/v1/webhooks/bank', [
            'bank_reference_id' => $this->bankReferenceId,
            'status' => $this->status,
        ]);
    }
}
