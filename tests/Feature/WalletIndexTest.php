<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallets_index_returns_paginated_envelope_with_context_fields(): void
    {
        $employee = Employee::factory()->create();

        Wallet::factory()->for($employee)->create(['type' => 'salary']);
        Wallet::factory()->for($employee)->savings()->create();

        $response = $this->getJson("/api/v1/employees/{$employee->id}/wallets?per_page=1&page=1");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Wallets retrieved successfully.')
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'employee_id',
                        'type',
                        'currency',
                        'balance',
                        'is_locked',
                        'created_at',
                    ],
                ],
                'meta' => [
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                    'from',
                    'to',
                ],
                'links' => ['first', 'last', 'prev', 'next'],
            ])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.employee_id', $employee->id)
            ->assertJsonPath('data.0.balance', '0.00');
    }

    public function test_wallet_show_returns_full_detail(): void
    {
        $wallet = Wallet::factory()->withBalance('1500.5000')->create(['is_locked' => false]);

        $this->getJson("/api/v1/wallets/{$wallet->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Wallet retrieved successfully.')
            ->assertJsonPath('data.id', $wallet->id)
            ->assertJsonPath('data.employee_id', $wallet->employee_id)
            ->assertJsonPath('data.balance', '1500.50')
            ->assertJsonPath('data.is_locked', false)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'employee_id',
                    'type',
                    'currency',
                    'balance',
                    'is_locked',
                    'created_at',
                ],
            ])
            ->assertJsonMissingPath('data.updated_at');
    }

    public function test_per_page_cannot_exceed_100(): void
    {
        $employee = Employee::factory()->create();

        $this->getJson("/api/v1/employees/{$employee->id}/wallets?per_page=200")
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['per_page']);
    }
}
