<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_between_same_employee_wallets_via_api(): void
    {
        $employee = Employee::factory()->create();
        $salary = Wallet::factory()
            ->for($employee)
            ->withBalance('5000.0000')
            ->create(['type' => Wallet::TYPE_SALARY, 'currency' => 'SAR']);
        $savings = Wallet::factory()
            ->for($employee)
            ->savings()
            ->withBalance('0.0000')
            ->create(['currency' => 'SAR']);

        $response = $this->postJson('/api/v1/transfers', [
            'from_wallet_id' => $salary->id,
            'to_wallet_id' => $savings->id,
            'amount' => '1000.0000',
            'reference_id' => 'api-transfer-001',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.debit.type', 'transfer_out')
            ->assertJsonPath('data.credit.type', 'transfer_in');

        $salary->refresh();
        $savings->refresh();

        $this->assertSame('4000.0000', (string) $salary->balance);
        $this->assertSame('1000.0000', (string) $savings->balance);
    }
}
