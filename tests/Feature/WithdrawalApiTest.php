<?php

namespace Tests\Feature;

use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithdrawalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_withdrawal_returns_structured_insufficient_balance_error(): void
    {
        $wallet = Wallet::factory()->withBalance('100.0000')->create();

        $response = $this->postJson("/api/v1/wallets/{$wallet->id}/withdrawals", [
            'amount' => '500.0000',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJson([
                'success' => false,
                'message' => 'Insufficient wallet balance.',
                'error_code' => 'INSUFFICIENT_BALANCE',
                'errors' => [
                    'amount' => [
                        'Requested amount (500.0000) exceeds available balance (100.0000).',
                    ],
                ],
            ])
            ->assertJsonMissing(['trace', 'exception', 'file']);
    }
}
