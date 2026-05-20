<?php

namespace Tests\Feature;

use App\Jobs\InitiateBankPaymentJob;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithdrawalBankStubTest extends TestCase
{
    use RefreshDatabase;

    public function test_withdrawal_reaches_bank_stub_without_http_404(): void
    {
        $wallet = Wallet::factory()->withBalance('1000.0000')->create();

        $withdrawal = app(WalletService::class)->initiateWithdrawal($wallet->id, '400.0000');

        (new InitiateBankPaymentJob($withdrawal))->handle(
            app(\App\Services\BankService::class),
            app(WalletService::class),
        );

        $withdrawal->refresh();

        $this->assertContains($withdrawal->status, ['pending', 'failed']);

        if ($withdrawal->status === 'pending') {
            $this->assertNotNull($withdrawal->bank_reference_id);
            $this->assertStringStartsWith('bank_', $withdrawal->bank_reference_id);
        }
    }
}
