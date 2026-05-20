<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollStubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.payroll.webhook_secret' => 'test-payroll-secret']);
    }

    public function test_payroll_stub_trigger_does_not_deadlock(): void
    {
        $employee = Employee::factory()->create(['external_id' => 'emp-stub-001']);
        Wallet::factory()->for($employee)->create();

        $response = $this->postJson('/stubs/payroll/trigger', [
            'event_type' => 'salary_run.processed',
            'payload' => [
                'run_id' => 'run-stub-test',
                'period_start' => '2026-05-01',
                'period_end' => '2026-05-31',
                'employees' => [
                    ['employee_id' => 'emp-stub-001', 'amount' => '5000.0000'],
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Payroll event triggered successfully.')
            ->assertJsonPath('data.event_type', 'salary_run.processed')
            ->assertJsonPath('data.webhook_status', 200)
            ->assertJsonPath('data.webhook_body.success', true);
    }
}
