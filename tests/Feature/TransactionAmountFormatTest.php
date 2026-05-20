<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionAmountFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_transactions_list_formats_amounts_with_two_decimals(): void
    {
        $wallet = Wallet::factory()->withBalance('1000.0000')->create();

        Transaction::query()->create([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount' => '500.0000',
            'balance_after' => '1500.0000',
            'reference_id' => 'test-format-ref',
        ]);

        $this->getJson("/api/v1/wallets/{$wallet->id}/transactions")
            ->assertOk()
            ->assertJsonPath('data.0.amount', '500.00')
            ->assertJsonPath('data.0.balance_after', '1500.00');
    }
}
