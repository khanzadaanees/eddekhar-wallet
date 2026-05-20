<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_up_returns_ok(): void
    {
        $this->get('/up')
            ->assertOk();
    }
}
