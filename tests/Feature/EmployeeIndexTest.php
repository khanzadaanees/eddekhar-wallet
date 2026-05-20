<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_employees_index_returns_paginated_envelope(): void
    {
        Employee::factory()
            ->count(20)
            ->create(['company_id' => 1])
            ->each(fn (Employee $employee) => Wallet::factory()->for($employee)->create());

        $response = $this->getJson('/api/v1/employees?per_page=15&page=1');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Employees retrieved successfully.')
            ->assertJsonCount(15, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'email',
                        'status',
                        'company_id',
                        'wallets' => [
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
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('links.prev', null);

        $this->assertStringContainsString('page=2', (string) $response->json('links.next'));
    }

    public function test_employees_index_filters_by_status_company_and_search(): void
    {
        Employee::factory()->create([
            'name' => 'Ahmed Al-Rashid',
            'email' => 'ahmed@company.com',
            'status' => 'active',
            'company_id' => 1,
        ]);

        Employee::factory()->create([
            'name' => 'Other Person',
            'email' => 'other@company.com',
            'status' => 'inactive',
            'company_id' => 2,
        ]);

        $this->getJson('/api/v1/employees?status=active&company_id=1&search=ahmed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ahmed Al-Rashid');
    }

    public function test_per_page_cannot_exceed_100(): void
    {
        $this->getJson('/api/v1/employees?per_page=999999')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['per_page']);
    }
}
