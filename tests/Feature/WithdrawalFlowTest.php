<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientBalanceException;
use App\Models\WithdrawalRequest;
use App\Models\Wallet;
use App\Services\BankService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class WithdrawalFlowTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $walletService;

    private const BANK_REFERENCE = 'bank_test_ref_001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->walletService = app(WalletService::class);
        $this->mockBankServiceAcceptance();
    }

    public function test_withdrawal_reserves_balance_immediately(): void
    {
        $wallet = Wallet::factory()->withBalance('1000.0000')->create();

        $withdrawal = $this->walletService->initiateWithdrawal($wallet->id, '400.0000');

        $wallet->refresh();

        $this->assertSame('600.0000', (string) $wallet->balance);
        $this->assertSame('pending', $withdrawal->status);
        $this->assertSame(self::BANK_REFERENCE, $withdrawal->fresh()->bank_reference_id);
        $this->assertDatabaseHas('transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'withdrawal_reserved',
            'amount' => '400.0000',
        ]);
    }

    public function test_confirmed_bank_callback_finalizes_withdrawal(): void
    {
        $wallet = Wallet::factory()->withBalance('1000.0000')->create();
        $withdrawal = $this->walletService->initiateWithdrawal($wallet->id, '400.0000');

        $response = $this->postJson('/api/v1/webhooks/bank', [
            'bank_reference_id' => self::BANK_REFERENCE,
            'status' => 'confirmed',
        ]);

        $response->assertOk();
        $withdrawal->refresh();
        $wallet->refresh();

        $this->assertSame('confirmed', $withdrawal->status);
        $this->assertNotNull($withdrawal->confirmed_at);
        $this->assertSame('600.0000', (string) $wallet->balance);
    }

    public function test_failed_bank_callback_refunds_balance(): void
    {
        $wallet = Wallet::factory()->withBalance('1000.0000')->create();
        $withdrawal = $this->walletService->initiateWithdrawal($wallet->id, '400.0000');

        $response = $this->postJson('/api/v1/webhooks/bank', [
            'bank_reference_id' => self::BANK_REFERENCE,
            'status' => 'failed',
        ]);

        $response->assertOk();
        $withdrawal->refresh();
        $wallet->refresh();

        $this->assertSame('failed', $withdrawal->status);
        $this->assertNotNull($withdrawal->failed_at);
        $this->assertSame('1000.0000', (string) $wallet->balance);
        $this->assertDatabaseHas('transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'withdrawal_refund',
            'reference_id' => "withdrawal_refund:{$withdrawal->id}",
        ]);
    }

    public function test_duplicate_bank_callback_is_ignored(): void
    {
        $wallet = Wallet::factory()->withBalance('1000.0000')->create();
        $this->walletService->initiateWithdrawal($wallet->id, '400.0000');

        $this->postJson('/api/v1/webhooks/bank', [
            'bank_reference_id' => self::BANK_REFERENCE,
            'status' => 'confirmed',
        ])->assertOk();

        $wallet->refresh();
        $balanceAfterFirstCallback = (string) $wallet->balance;

        $this->postJson('/api/v1/webhooks/bank', [
            'bank_reference_id' => self::BANK_REFERENCE,
            'status' => 'confirmed',
        ])->assertOk();

        $wallet->refresh();

        $this->assertSame($balanceAfterFirstCallback, (string) $wallet->balance);
        $this->assertSame(
            1,
            WithdrawalRequest::query()->where('bank_reference_id', self::BANK_REFERENCE)->count(),
        );
    }

    public function test_cannot_spend_reserved_amount(): void
    {
        $wallet = Wallet::factory()->withBalance('1000.0000')->create();

        $this->walletService->initiateWithdrawal($wallet->id, '1000.0000');

        $wallet->refresh();
        $this->assertSame('0.0000', (string) $wallet->balance);

        $this->expectException(InsufficientBalanceException::class);

        $this->walletService->debit($wallet->id, '0.0001', 'debit-after-reserve');
    }

    private function mockBankServiceAcceptance(): void
    {
        $this->mock(BankService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('initiatePayment')
                ->andReturnUsing(function (WithdrawalRequest $withdrawal): void {
                    $withdrawal->update([
                        'bank_reference_id' => self::BANK_REFERENCE,
                    ]);
                });
        });
    }
}
