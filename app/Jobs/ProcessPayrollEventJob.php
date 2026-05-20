<?php

namespace App\Jobs;

use App\Exceptions\WalletLockedException;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Wallet;
use App\Services\WalletService;
use App\Support\PayrollWebhookPayload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ProcessPayrollEventJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function __construct(
        public PayrollRun $payrollRun,
    ) {}

    public function handle(WalletService $walletService): void
    {
        $payload = $this->payrollRun->raw_payload;
        $eventType = $payload['event_type'] ?? null;

        $this->payrollRun->update([
            'status' => 'processing',
            'processed_by' => $this->resolveProcessedBy(),
        ]);

        try {
            $failures = match ($eventType) {
                'employee.onboarded' => $this->handleEmployeeOnboarded($payload),
                'employee.status_changed' => $this->handleEmployeeStatusChanged($payload),
                'salary_run.processed' => $this->handleSalaryRunProcessed($payload, $walletService),
                default => throw new \InvalidArgumentException("Unsupported event type [{$eventType}]."),
            };

            $this->finalizePayrollRun($failures);
        } catch (\Throwable $exception) {
            $this->payrollRun->update(['status' => 'failed']);

            Log::error('Payroll event processing failed', [
                'payroll_run_id' => $this->payrollRun->id,
                'event_type' => $eventType,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<int, array{external_id: string, reason: string}>  $failures
     */
    private function finalizePayrollRun(array $failures): void
    {
        if ($failures !== []) {
            Log::error('PayrollRun completed with failures.', [
                'payroll_run_id' => $this->payrollRun->id,
                'external_id' => $this->payrollRun->external_id,
                'failures' => $failures,
            ]);
        }

        $this->payrollRun->update([
            'status' => $failures === []
                ? 'completed'
                : PayrollRun::STATUS_COMPLETED_WITH_ERRORS,
            'processed_at' => now(),
            'processing_errors' => $failures === [] ? null : $failures,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{external_id: string, reason: string}>
     */
    private function handleEmployeeOnboarded(array $payload): array
    {
        $data = PayrollWebhookPayload::eventData($payload);

        $employee = Employee::query()->updateOrCreate(
            ['external_id' => (string) $data['employee_id']],
            [
                'name' => $data['name'],
                'email' => $data['email'],
                'company_id' => (int) $data['company_id'],
                'status' => $data['status'] ?? Employee::STATUS_ACTIVE,
            ],
        );

        Wallet::query()->firstOrCreate(
            [
                'employee_id' => $employee->id,
                'type' => Wallet::TYPE_SALARY,
            ],
            [
                'currency' => $data['currency'] ?? 'SAR',
                'balance' => '0.0000',
                'is_locked' => false,
            ],
        );

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{external_id: string, reason: string}>
     */
    private function handleEmployeeStatusChanged(array $payload): array
    {
        $data = PayrollWebhookPayload::eventData($payload);

        $employee = Employee::query()
            ->where('external_id', (string) $data['employee_id'])
            ->firstOrFail();

        $employee->update([
            'status' => $data['status'],
        ]);

        $this->syncWalletLocksForStatus($employee);

        if ($employee->status === Employee::STATUS_TERMINATED) {
            Log::info('Employee terminated — all wallets locked.', [
                'employee_id' => $employee->id,
                'external_id' => $employee->external_id,
            ]);
        }

        return [];
    }

    /**
     * Terminated employees cannot spend; unlock when returned to active/inactive.
     */
    private function syncWalletLocksForStatus(Employee $employee): void
    {
        $shouldLock = $employee->status === Employee::STATUS_TERMINATED;

        $employee->wallets()->update(['is_locked' => $shouldLock]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{external_id: string, reason: string}>
     */
    private function handleSalaryRunProcessed(array $payload, WalletService $walletService): array
    {
        $data = PayrollWebhookPayload::eventData($payload);
        $runId = (string) ($data['run_id'] ?? $this->payrollRun->external_id);
        $employees = $data['employees'] ?? [];
        $failures = [];

        foreach ($employees as $item) {
            $externalId = (string) data_get($item, 'employee_id', '');
            $amount = (string) data_get($item, 'amount', '');

            if ($externalId === '' || $amount === '') {
                $failures[] = [
                    'external_id' => $externalId !== '' ? $externalId : 'unknown',
                    'reason' => 'Missing employee_id or amount',
                ];

                continue;
            }

            $employee = Employee::query()
                ->where('external_id', $externalId)
                ->first();

            if ($employee === null) {
                Log::warning('PayrollRun: Employee not found, skipping.', [
                    'run_id' => $runId,
                    'external_id' => $externalId,
                ]);

                $failures[] = [
                    'external_id' => $externalId,
                    'reason' => 'Employee not found',
                ];

                continue;
            }

            if ($employee->status === Employee::STATUS_TERMINATED) {
                Log::warning('PayrollRun: Skipping salary for terminated employee.', [
                    'run_id' => $runId,
                    'external_id' => $externalId,
                ]);

                $failures[] = [
                    'external_id' => $externalId,
                    'reason' => 'Employee terminated',
                ];

                continue;
            }

            $wallet = $employee->wallets()
                ->where('type', Wallet::TYPE_SALARY)
                ->first();

            if ($wallet === null) {
                Log::warning('PayrollRun: Salary wallet not found, skipping.', [
                    'run_id' => $runId,
                    'employee_id' => $employee->id,
                    'external_id' => $externalId,
                ]);

                $failures[] = [
                    'external_id' => $externalId,
                    'reason' => 'Salary wallet not found',
                ];

                continue;
            }

            try {
                $walletService->credit(
                    $wallet->id,
                    $amount,
                    "payroll_{$runId}_{$externalId}",
                    'credit',
                );
            } catch (WalletLockedException $exception) {
                Log::warning('PayrollRun: Credit blocked — wallet locked.', [
                    'run_id' => $runId,
                    'external_id' => $externalId,
                    'wallet_id' => $wallet->id,
                ]);

                $failures[] = [
                    'external_id' => $externalId,
                    'reason' => 'Wallet is locked',
                ];
            } catch (\Throwable $exception) {
                Log::warning('PayrollRun: Credit failed, skipping.', [
                    'run_id' => $runId,
                    'external_id' => $externalId,
                    'error' => $exception->getMessage(),
                ]);

                $failures[] = [
                    'external_id' => $externalId,
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        if (isset($data['period_start'], $data['period_end'])) {
            $this->payrollRun->update([
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
            ]);
        }

        return $failures;
    }

    /**
     * Queue job id / connection for debugging duplicate or stuck runs (sync tests use a fallback).
     */
    private function resolveProcessedBy(): string
    {
        $job = $this->job;
        $connection = $job?->getConnectionName() ?? 'sync';
        $id = $job?->getJobId();

        if ($id !== null && $id !== '') {
            return "{$connection}:{$id}";
        }

        return "{$connection}:payroll_run:{$this->payrollRun->id}";
    }
}
