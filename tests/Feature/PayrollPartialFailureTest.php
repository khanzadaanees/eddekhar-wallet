<?php

namespace Tests\Feature;

use App\Jobs\ProcessPayrollEventJob;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PayrollPartialFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.payroll.webhook_secret' => 'test-payroll-secret']);
    }

    public function test_salary_run_skips_unknown_employee_and_credits_valid_ones(): void
    {
        Queue::fake();

        $employee = Employee::factory()->create(['external_id' => 'emp-valid']);
        Wallet::factory()->for($employee)->withBalance('100.0000')->create();

        $payload = [
            'event_type' => 'salary_run.processed',
            'payload' => [
                'run_id' => 'run-partial-1',
                'employees' => [
                    ['employee_id' => 'emp-valid', 'amount' => '500.0000'],
                    ['employee_id' => 'emp-does-not-exist', 'amount' => '1000.0000'],
                ],
            ],
        ];

        $this->postSignedPayrollWebhook($payload)->assertOk();

        $payrollRun = PayrollRun::query()->where('external_id', 'run-partial-1')->firstOrFail();

        (new ProcessPayrollEventJob($payrollRun))->handle(app(\App\Services\WalletService::class));

        $payrollRun->refresh();
        $employee->wallets()->first()->refresh();

        $this->assertSame(PayrollRun::STATUS_COMPLETED_WITH_ERRORS, $payrollRun->status);
        $this->assertNotNull($payrollRun->processed_at);
        $this->assertCount(1, $payrollRun->processing_errors);
        $this->assertSame('emp-does-not-exist', $payrollRun->processing_errors[0]['external_id']);
        $this->assertSame('600.0000', (string) $employee->wallets()->first()->balance);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSignedPayrollWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $timestamp = (string) time();
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, config('services.payroll.webhook_secret'));

        return $this->call(
            'POST',
            '/api/v1/webhooks/payroll',
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'X-Payroll-Timestamp' => $timestamp,
                'X-Payroll-Signature' => $signature,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]),
            $body,
        );
    }
}
