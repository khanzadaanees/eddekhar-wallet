<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookReplayException extends Exception
{
    public function __construct()
    {
        parent::__construct('Webhook request has expired.');
    }

    public function render(Request $request): JsonResponse
    {
        return ApiResponse::error(
            'Webhook request has expired.',
            'WEBHOOK_REPLAY_DETECTED',
            401,
        );
    }
}
