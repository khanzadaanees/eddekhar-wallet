<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Exceptions\WebhookReplayException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\PayrollWebhookPayload;
use App\Jobs\ProcessPayrollEventJob;
use App\Models\PayrollRun;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PayrollWebhookController extends Controller
{
    private const ALLOWED_EVENT_TYPES = [
        'employee.onboarded',
        'employee.status_changed',
        'salary_run.processed',
    ];

    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public function handle(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $timestamp = $request->header('X-Payroll-Timestamp');
        $signature = $request->header('X-Payroll-Signature');

        if (! $timestamp || ! $signature) {
            return ApiResponse::error(
                'Missing required headers.',
                'WEBHOOK_UNAUTHORIZED',
                401,
            );
        }

        if (! $this->isTimestampValid($timestamp)) {
            throw new WebhookReplayException;
        }

        if (! $this->isSignatureValid($timestamp, $body, $signature)) {
            return ApiResponse::error(
                'Invalid signature.',
                'WEBHOOK_UNAUTHORIZED',
                401,
            );
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return ApiResponse::error(
                'Invalid JSON payload.',
                'INVALID_PAYLOAD',
                400,
            );
        }

        $eventType = $payload['event_type'] ?? null;

        if (! in_array($eventType, self::ALLOWED_EVENT_TYPES, true)) {
            return ApiResponse::error(
                'Unsupported event type.',
                'UNSUPPORTED_EVENT',
                422,
            );
        }

        $eventData = PayrollWebhookPayload::eventData($payload);

        if ($eventType === 'salary_run.processed' && ! $this->hasStableSalaryRunIdentifier($eventData)) {
            return ApiResponse::error(
                'salary_run.processed requires a stable run_id or id in the event payload so retries dedupe to one PayrollRun and one credit per employee.',
                'MISSING_RUN_IDENTIFIER',
                422,
            );
        }

        $externalId = $this->resolveExternalId($payload, $eventType);
        $idempotencyKey = $this->normalizeIdempotencyKey($request->header('Idempotency-Key'));

        $payrollRun = null;
        $shouldDispatch = false;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                [$payrollRun, $shouldDispatch] = DB::transaction(function () use ($payload, $eventData, $externalId, $idempotencyKey) {
                    return $this->resolvePayrollRunForWebhook($payload, $eventData, $externalId, $idempotencyKey);
                });

                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt === 2) {
                    throw $e;
                }
            }
        }

        if ($shouldDispatch) {
            ProcessPayrollEventJob::dispatch($payrollRun);
        }

        return ApiResponse::success('Accepted');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $eventData
     * @return array{0: PayrollRun, 1: bool}
     */
    private function resolvePayrollRunForWebhook(
        array $payload,
        array $eventData,
        string $externalId,
        ?string $idempotencyKey,
    ): array {
        if ($idempotencyKey !== null) {
            $byKey = PayrollRun::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($byKey !== null) {
                return $this->terminalAwareDispatchTuple($byKey);
            }
        }

        $payrollRun = PayrollRun::query()
            ->where('external_id', $externalId)
            ->lockForUpdate()
            ->first();

        if ($payrollRun !== null) {
            return $this->terminalAwareDispatchTuple($payrollRun);
        }

        $payrollRun = PayrollRun::query()->create([
            'external_id' => $externalId,
            'period_start' => $eventData['period_start'] ?? now()->startOfMonth()->toDateString(),
            'period_end' => $eventData['period_end'] ?? now()->endOfMonth()->toDateString(),
            'status' => 'pending',
            'raw_payload' => $payload,
            'idempotency_key' => $idempotencyKey,
        ]);

        return [$payrollRun, true];
    }

    /**
     * @return array{0: PayrollRun, 1: bool}
     */
    private function terminalAwareDispatchTuple(PayrollRun $payrollRun): array
    {
        if (in_array($payrollRun->status, ['completed', PayrollRun::STATUS_COMPLETED_WITH_ERRORS], true)) {
            return [$payrollRun, false];
        }

        if ($payrollRun->status === 'failed') {
            $payrollRun->update(['status' => 'pending']);

            return [$payrollRun, true];
        }

        return [$payrollRun, false];
    }

    private function normalizeIdempotencyKey(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }

        $key = trim($header);

        if ($key === '') {
            return null;
        }

        return mb_substr($key, 0, 255);
    }

    private function isTimestampValid(string $timestamp): bool
    {
        if (! ctype_digit($timestamp)) {
            return false;
        }

        $age = time() - (int) $timestamp;

        return $age >= 0 && $age <= self::TIMESTAMP_TOLERANCE_SECONDS;
    }

    private function isSignatureValid(string $timestamp, string $body, string $signature): bool
    {
        $secret = config('services.payroll.webhook_secret');

        if (! $secret) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveExternalId(array $payload, string $eventType): string
    {
        $eventData = PayrollWebhookPayload::eventData($payload);

        if ($this->nonEmptyString($eventData['run_id'] ?? null)) {
            return (string) $eventData['run_id'];
        }

        if ($this->nonEmptyString($eventData['id'] ?? null)) {
            return (string) $eventData['id'];
        }

        // salary_run.processed must not reach here (validated in handle()).
        return $eventType.'_'.Str::uuid()->toString();
    }

    /**
     * @param  array<string, mixed>  $eventData
     */
    private function hasStableSalaryRunIdentifier(array $eventData): bool
    {
        return $this->nonEmptyString($eventData['run_id'] ?? null)
            || $this->nonEmptyString($eventData['id'] ?? null);
    }

    private function nonEmptyString(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return trim((string) $value) !== '';
    }
}
