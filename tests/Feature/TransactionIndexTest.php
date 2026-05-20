<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_transactions_index_returns_pagination_meta_and_links(): void
    {
        $wallet = Wallet::factory()->create();

        for ($i = 0; $i < 20; $i++) {
            Transaction::query()->create([
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'amount' => '10.0000',
                'balance_after' => '10.0000',
                'reference_id' => "ref-{$i}",
            ]);
        }

        $response = $this->getJson("/api/v1/wallets/{$wallet->id}/transactions?per_page=5&page=1");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'wallet_id',
                        'type',
                        'description',
                        'amount',
                        'balance_after',
                        'reference_id',
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
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.last_page', 4)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_transactions_index_filters_by_type_and_date(): void
    {
        $wallet = Wallet::factory()->create();

        $credit = Transaction::query()->create([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount' => '100.0000',
            'balance_after' => '100.0000',
            'reference_id' => 'credit-ref',
        ]);
        $credit->forceFill(['created_at' => '2026-05-10 12:00:00'])->save();

        $debit = Transaction::query()->create([
            'wallet_id' => $wallet->id,
            'type' => 'debit',
            'amount' => '50.0000',
            'balance_after' => '50.0000',
            'reference_id' => 'debit-ref',
        ]);
        $debit->forceFill(['created_at' => '2026-05-20 12:00:00'])->save();

        $this->getJson("/api/v1/wallets/{$wallet->id}/transactions?type=credit&date_from=2026-05-01&date_to=2026-05-15")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'credit');
    }
}
