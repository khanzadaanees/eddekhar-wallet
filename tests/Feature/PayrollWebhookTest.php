<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.payroll.webhook_secret' => 'test-payroll-secret']);
    }

    public function test_salary_run_credits_correct_wallets(): void
    {
        $employeeOne = Employee::factory()->create(['external_id' => 'emp-payroll-1']);
        $employeeTwo = Employee::factory()->create(['external_id' => 'emp-payroll-2']);
        $walletOne = Wallet::factory()->for($employeeOne)->withBalance('100.0000')->create();
        $walletTwo = Wallet::factory()->for($employeeTwo)->withBalance('50.0000')->create();

        $payload = [
            'event_type' => 'salary_run.processed',
            'payload' => [
                'run_id' => 'run-2026-05',
                'period_start' => '2026-05-01',
                'period_end' => '2026-05-31',
                'employees' => [
                    ['employee_id' => 'emp-payroll-1', 'amount' => '5000.0000'],
                    ['employee_id' => 'emp-payroll-2', 'amount' => '3000.0000'],
                ],
            ],
        ];

        $this->postSignedPayrollWebhook($payload)->assertOk();

        $walletOne->refresh();
        $walletTwo->refresh();

        $this->assertSame('5100.0000', (string) $walletOne->balance);
        $this->assertSame('3050.0000', (string) $walletTwo->balance);
        $this->assertDatabaseHas('transactions', [
            'wallet_id' => $walletOne->id,
            'reference_id' => 'payroll_run-2026-05_emp-payroll-1',
        ]);
        $this->assertDatabaseHas('payroll_runs', [
            'external_id' => 'run-2026-05',
            'status' => 'completed',
        ]);
    }

    public function test_duplicate_salary_run_does_not_double_credit(): void
    {
        $employee = Employee::factory()->create(['external_id' => 'emp-dup-run']);
        $wallet = Wallet::factory()->for($employee)->withBalance('0.0000')->create();

        $payload = [
            'event_type' => 'salary_run.processed',
            'payload' => [
                'run_id' => 'run-dup-1',
                'employees' => [
                    ['employee_id' => 'emp-dup-run', 'amount' => '2000.0000'],
                ],
            ],
        ];

        $this->postSignedPayrollWebhook($payload)->assertOk();
        $this->postSignedPayrollWebhook($payload)->assertOk();

        $wallet->refresh();

        $this->assertSame('2000.0000', (string) $wallet->balance);
        $this->assertSame(
            1,
            Transaction::query()->where('reference_id', 'payroll_run-dup-1_emp-dup-run')->count(),
        );
        $this->assertSame(1, PayrollRun::query()->where('external_id', 'run-dup-1')->count());
    }

    public function test_idempotency_key_header_deduplicates_duplicate_requests(): void
    {
        $employee = Employee::factory()->create(['external_id' => 'emp-idem-1']);
        Wallet::factory()->for($employee)->withBalance('0.0000')->create();

        $payload = [
            'event_type' => 'salary_run.processed',
            'payload' => [
                'run_id' => 'run-idem-xyz',
                'employees' => [
                    ['employee_id' => 'emp-idem-1', 'amount' => '100.0000'],
                ],
            ],
        ];

        $this->postSignedPayrollWebhook($payload, null, 'payroll-idem-key-1')->assertOk();
        $this->postSignedPayrollWebhook($payload, null, 'payroll-idem-key-1')->assertOk();

        $employee->wallets()->first()->refresh();

        $this->assertSame('100.0000', (string) $employee->wallets()->first()->balance);
        $this->assertSame(1, PayrollRun::query()->count());

        $run = PayrollRun::query()->firstOrFail();
        $this->assertSame('payroll-idem-key-1', $run->idempotency_key);
        $this->assertNotNull($run->processed_by);
        $this->assertStringStartsWith('sync:', $run->processed_by);
    }

    public function test_salary_run_without_run_id_or_id_returns_422_and_does_not_create_payroll_run(): void
    {
        $employee = Employee::factory()->create(['external_id' => 'emp-no-run-id']);
        Wallet::factory()->for($employee)->withBalance('0.0000')->create();

        $payload = [
            'event_type' => 'salary_run.processed',
            'payload' => [
                'employees' => [
                    ['employee_id' => 'emp-no-run-id', 'amount' => '100.0000'],
                ],
            ],
        ];

        $this->postSignedPayrollWebhook($payload)
            ->assertUnprocessable()
            ->assertJson([
                'success' => false,
                'error_code' => 'MISSING_RUN_IDENTIFIER',
            ]);

        $this->assertSame(0, PayrollRun::query()->count());
        $wallet = Wallet::query()->where('employee_id', $employee->id)->firstOrFail();
        $wallet->refresh();
        $this->assertSame('0.0000', (string) $wallet->balance);
    }

    public function test_salary_run_accepts_id_as_stable_dedupe_key_instead_of_run_id(): void
    {
        $employee = Employee::factory()->create(['external_id' => 'emp-run-by-id']);
        Wallet::factory()->for($employee)->withBalance('0.0000')->create();

        $payload = [
            'event_type' => 'salary_run.processed',
            'payload' => [
                'id' => 'ledger-batch-99',
                'employees' => [
                    ['employee_id' => 'emp-run-by-id', 'amount' => '500.0000'],
                ],
            ],
        ];

        $this->postSignedPayrollWebhook($payload)->assertOk();
        $this->postSignedPayrollWebhook($payload)->assertOk();

        $wallet = Wallet::query()->where('employee_id', $employee->id)->firstOrFail();
        $wallet->refresh();
        $this->assertSame('500.0000', (string) $wallet->balance);
        $this->assertSame(1, PayrollRun::query()->where('external_id', 'ledger-batch-99')->count());
    }

    public function test_invalid_signature_returns_401(): void
    {
        $payload = [
            'event_type' => 'salary_run.processed',
            'payload' => [
                'run_id' => 'run-invalid-sig',
                'employees' => [],
            ],
        ];

        $timestamp = (string) time();
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/v1/webhooks/payroll',
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'X-Payroll-Timestamp' => $timestamp,
                'X-Payroll-Signature' => 'invalid-signature',
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]),
            $body,
        )->assertUnauthorized();
    }

    public function test_replay_attack_returns_401(): void
    {
        $payload = [
            'event_type' => 'salary_run.processed',
            'payload' => [
                'run_id' => 'run-replay',
                'employees' => [],
            ],
        ];

        $expiredTimestamp = (string) (time() - 600);

        $this->postSignedPayrollWebhook($payload, $expiredTimestamp)
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Webhook request has expired.',
                'error_code' => 'WEBHOOK_REPLAY_DETECTED',
            ]);
    }

    public function test_status_changed_terminated_locks_all_wallets_and_active_unlocks(): void
    {
        $employee = Employee::factory()->create(['external_id' => 'emp-lock-test']);
        $salary = Wallet::factory()->for($employee)->create([
            'type' => Wallet::TYPE_SALARY,
            'is_locked' => false,
        ]);
        $savings = Wallet::factory()->for($employee)->savings()->create(['is_locked' => false]);

        $this->postSignedPayrollWebhook([
            'event_type' => 'employee.status_changed',
            'payload' => [
                'employee_id' => 'emp-lock-test',
                'status' => Employee::STATUS_TERMINATED,
            ],
        ])->assertOk();

        $salary->refresh();
        $savings->refresh();
        $this->assertTrue($salary->is_locked);
        $this->assertTrue($savings->is_locked);

        $this->postSignedPayrollWebhook([
            'event_type' => 'employee.status_changed',
            'payload' => [
                'employee_id' => 'emp-lock-test',
                'status' => Employee::STATUS_ACTIVE,
            ],
        ])->assertOk();

        $salary->refresh();
        $savings->refresh();
        $this->assertFalse($salary->is_locked);
        $this->assertFalse($savings->is_locked);
    }

    public function test_salary_run_skips_terminated_employee_without_crediting(): void
    {
        $employee = Employee::factory()->create([
            'external_id' => 'emp-term-salary',
            'status' => Employee::STATUS_TERMINATED,
        ]);
        $wallet = Wallet::factory()->for($employee)->withBalance('100.0000')->create([
            'type' => Wallet::TYPE_SALARY,
            'is_locked' => true,
        ]);

        $this->postSignedPayrollWebhook([
            'event_type' => 'salary_run.processed',
            'payload' => [
                'run_id' => 'run-term-skip-1',
                'employees' => [
                    ['employee_id' => 'emp-term-salary', 'amount' => '5000.0000'],
                ],
            ],
        ])->assertOk();

        $wallet->refresh();

        $this->assertSame('100.0000', (string) $wallet->balance);
        $this->assertSame(
            0,
            Transaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('reference_id', 'payroll_run-term-skip-1_emp-term-salary')
                ->count(),
        );

        $run = PayrollRun::query()->where('external_id', 'run-term-skip-1')->firstOrFail();
        $this->assertSame(PayrollRun::STATUS_COMPLETED_WITH_ERRORS, $run->status);
        $this->assertIsArray($run->processing_errors);
        $this->assertSame('Employee terminated', $run->processing_errors[0]['reason'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSignedPayrollWebhook(array $payload, ?string $timestamp = null, ?string $idempotencyKey = null): \Illuminate\Testing\TestResponse
    {
        $timestamp ??= (string) time();
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, config('services.payroll.webhook_secret'));

        $headers = [
            'X-Payroll-Timestamp' => $timestamp,
            'X-Payroll-Signature' => $signature,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->call(
            'POST',
            '/api/v1/webhooks/payroll',
            [],
            [],
            [],
            $this->transformHeadersToServerVars($headers),
            $body,
        );
    }
}
