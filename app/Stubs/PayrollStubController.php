<?php

namespace App\Stubs;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PayrollStubController extends Controller
{
    public function trigger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_type' => ['required', 'string', 'in:employee.onboarded,employee.status_changed,salary_run.processed'],
            'payload' => ['required', 'array'],
        ]);

        $body = json_encode([
            'event_type' => $validated['event_type'],
            'payload' => $validated['payload'],
        ], JSON_THROW_ON_ERROR);

        $timestamp = (string) time();
        $secret = config('services.payroll.webhook_secret');

        if (! $secret) {
            return ApiResponse::error(
                'PAYROLL_WEBHOOK_SECRET is not configured.',
                'CONFIG_ERROR',
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        // Internal sub-request avoids HTTP loopback deadlock on `php artisan serve`.
        $webhookRequest = Request::create(
            '/api/v1/webhooks/payroll',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_PAYROLL_TIMESTAMP' => $timestamp,
                'HTTP_X_PAYROLL_SIGNATURE' => $signature,
            ],
            $body,
        );

        $webhookResponse = app()->handle($webhookRequest);
        $decoded = json_decode($webhookResponse->getContent(), true);
        $webhookBody = is_array($decoded) ? $decoded : $webhookResponse->getContent();

        $data = [
            'event_type' => $validated['event_type'],
            'webhook_status' => $webhookResponse->getStatusCode(),
            'webhook_body' => $webhookBody,
        ];

        if ($webhookResponse->isSuccessful()) {
            return ApiResponse::success(
                'Payroll event triggered successfully.',
                $data,
            );
        }

        return response()->json([
            'success' => false,
            'message' => 'Payroll webhook rejected the event.',
            'error_code' => 'WEBHOOK_REJECTED',
            'data' => $data,
        ], $webhookResponse->getStatusCode());
    }
}
