<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreWalletTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_savings_wallet_for_employee_with_salary_wallet(): void
    {
        $employee = Employee::factory()->create();
        Wallet::factory()->for($employee)->create([
            'type' => Wallet::TYPE_SALARY,
            'currency' => 'SAR',
        ]);

        $response = $this->postJson("/api/v1/employees/{$employee->id}/wallets", [
            'type' => Wallet::TYPE_SAVINGS,
            'currency' => 'sar',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Wallet created successfully.')
            ->assertJsonPath('data.type', Wallet::TYPE_SAVINGS)
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonPath('data.balance', '0.00')
            ->assertJsonPath('data.employee_id', $employee->id);

        $this->assertDatabaseHas('wallets', [
            'employee_id' => $employee->id,
            'type' => Wallet::TYPE_SAVINGS,
            'currency' => 'SAR',
            'balance' => '0.0000',
        ]);
    }

    public function test_cannot_create_duplicate_wallet_type(): void
    {
        $employee = Employee::factory()->create();
        Wallet::factory()->for($employee)->create([
            'type' => Wallet::TYPE_SAVINGS,
            'currency' => 'SAR',
        ]);

        $this->postJson("/api/v1/employees/{$employee->id}/wallets", [
            'type' => Wallet::TYPE_SAVINGS,
            'currency' => 'SAR',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['type']);
    }

    public function test_cannot_create_wallet_for_terminated_employee(): void
    {
        $employee = Employee::factory()->create([
            'status' => Employee::STATUS_TERMINATED,
        ]);
        Wallet::factory()->for($employee)->create([
            'type' => Wallet::TYPE_SALARY,
            'currency' => 'SAR',
        ]);

        $this->postJson("/api/v1/employees/{$employee->id}/wallets", [
            'type' => Wallet::TYPE_SAVINGS,
            'currency' => 'SAR',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employee']);
    }
}
