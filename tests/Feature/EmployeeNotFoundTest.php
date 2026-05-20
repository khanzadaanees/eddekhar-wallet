<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeNotFoundTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_employee_returns_clean_404_when_not_found(): void
    {
        $this->getJson('/api/v1/employees/999')
            ->assertNotFound()
            ->assertJson([
                'success' => false,
                'message' => 'Employee not found.',
                'error_code' => 'RESOURCE_NOT_FOUND',
            ])
            ->assertJsonMissing(['trace', 'exception']);
    }
}
