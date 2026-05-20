<?php

namespace Tests\Feature;

use App\Exceptions\CrossEmployeeTransferNotAllowedException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\SameCurrencyRequiredException;
use App\Exceptions\WalletLockedException;
use App\Models\Employee;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $walletService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->walletService = app(WalletService::class);
    }

    public function test_credit_increases_balance_correctly(): void
    {
        $wallet = Wallet::factory()->withBalance('1000.0000')->create();

        $this->walletService->credit($wallet->id, '250.5000', 'credit-ref-1');

        $wallet->refresh();

        $this->assertSame('1250.5000', (string) $wallet->balance);
        $this->assertDatabaseHas('transactions', [
            'wallet_id' => $wallet->id,
            'reference_id' => 'credit-ref-1',
            'type' => 'credit',
            'amount' => '250.5000',
            'balance_after' => '1250.5000',
        ]);
    }

    public function test_debit_decreases_balance_correctly(): void
    {
        $wallet = Wallet::factory()->withBalance('1000.0000')->create();

        $this->walletService->debit($wallet->id, '150.2500', 'debit-ref-1');

        $wallet->refresh();

        $this->assertSame('849.7500', (string) $wallet->balance);
    }

    public function test_debit_throws_when_insufficient_balance(): void
    {
        $wallet = Wallet::factory()->withBalance('50.0000')->create();

        $this->expectException(InsufficientBalanceException::class);

        $this->walletService->debit($wallet->id, '100.0000', 'debit-ref-insufficient');
    }

    public function test_duplicate_reference_id_does_not_double_credit(): void
    {
        $wallet = Wallet::factory()->withBalance('0.0000')->create();

        $this->walletService->credit($wallet->id, '100.0000', 'idempotent-ref-1');
        $this->walletService->credit($wallet->id, '100.0000', 'idempotent-ref-1');

        $wallet->refresh();

        $this->assertSame('100.0000', (string) $wallet->balance);
        $this->assertSame(1, Transaction::query()->where('reference_id', 'idempotent-ref-1')->count());
    }

    public function test_credit_throws_on_locked_wallet(): void
    {
        $wallet = Wallet::factory()->withBalance('100.0000')->create(['is_locked' => true]);

        $this->expectException(WalletLockedException::class);

        $this->walletService->credit($wallet->id, '10.0000', 'credit-locked-ref');
    }

    public function test_credit_idempotent_returns_existing_when_wallet_now_locked(): void
    {
        $wallet = Wallet::factory()->withBalance('0.0000')->create(['is_locked' => false]);
        $this->walletService->credit($wallet->id, '50.0000', 'idemp-after-lock');
        $wallet->update(['is_locked' => true]);
        $wallet->refresh();

        $tx = $this->walletService->credit($wallet->id, '999.0000', 'idemp-after-lock');

        $this->assertSame('50.0000', (string) $tx->amount);
        $wallet->refresh();
        $this->assertSame('50.0000', (string) $wallet->balance);
    }

    public function test_transfer_moves_funds_between_wallets(): void
    {
        $employee = Employee::factory()->create();
        $fromWallet = Wallet::factory()
            ->for($employee)
            ->withBalance('1000.0000')
            ->create(['type' => 'salary', 'currency' => 'SAR']);
        $toWallet = Wallet::factory()
            ->for($employee)
            ->savings()
            ->withBalance('200.0000')
            ->create(['currency' => 'SAR']);

        $result = $this->walletService->transfer(
            $fromWallet->id,
            $toWallet->id,
            '300.0000',
            'transfer-ref-1',
        );

        $fromWallet->refresh();
        $toWallet->refresh();

        $this->assertSame('700.0000', (string) $fromWallet->balance);
        $this->assertSame('500.0000', (string) $toWallet->balance);
        $this->assertSame('transfer_out', $result['debit']->type);
        $this->assertSame('transfer_in', $result['credit']->type);
    }

    public function test_transfer_rejects_wallets_of_different_employees(): void
    {
        $employeeA = Employee::factory()->create(['company_id' => 1]);
        $employeeB = Employee::factory()->create(['company_id' => 1]);

        $fromWallet = Wallet::factory()
            ->for($employeeA)
            ->withBalance('1000.0000')
            ->create(['currency' => 'SAR']);
        $toWallet = Wallet::factory()
            ->for($employeeB)
            ->savings()
            ->withBalance('0.0000')
            ->create(['currency' => 'SAR']);

        $this->expectException(CrossEmployeeTransferNotAllowedException::class);

        $this->walletService->transfer(
            $fromWallet->id,
            $toWallet->id,
            '100.0000',
            'transfer-ref-cross-employee',
        );
    }

    public function test_transfer_fails_for_different_currencies(): void
    {
        $employee = Employee::factory()->create();
        $fromWallet = Wallet::factory()
            ->for($employee)
            ->withBalance('1000.0000')
            ->create(['currency' => 'SAR']);
        $toWallet = Wallet::factory()
            ->for($employee)
            ->savings()
            ->withBalance('0.0000')
            ->create(['currency' => 'USD']);

        $this->expectException(SameCurrencyRequiredException::class);

        $this->walletService->transfer(
            $fromWallet->id,
            $toWallet->id,
            '100.0000',
            'transfer-ref-currency-mismatch',
        );
    }
}
