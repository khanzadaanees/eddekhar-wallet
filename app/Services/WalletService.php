<?php

namespace App\Services;

use App\Events\WalletCredited;
use App\Events\WalletDebited;
use App\Exceptions\CrossEmployeeTransferNotAllowedException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\SameCurrencyRequiredException;
use App\Exceptions\WalletLockedException;
use App\Exceptions\WalletNotFoundException;
use App\Jobs\InitiateBankPaymentJob;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WalletService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
    ) {}

    public function credit(
        int $walletId,
        string $amount,
        string $referenceId,
        string $type = 'credit',
    ): Transaction {
        return DB::transaction(function () use ($walletId, $amount, $referenceId, $type) {
            $existing = $this->transactionRepository->findByReferenceId($referenceId);
            if ($existing !== null) {
                return $existing;
            }

            $wallet = $this->lockWallet($walletId);

            if ($wallet->is_locked) {
                Log::warning('Credit attempted on locked wallet.', [
                    'wallet_id' => $walletId,
                    'reference_id' => $referenceId,
                    'amount' => $amount,
                ]);
            }

            $this->assertWalletNotLocked($wallet);

            $transaction = $this->recordCredit($wallet, $amount, $referenceId, $type);

            event(new WalletCredited($wallet, $transaction));

            return $transaction;
        });
    }

    public function debit(
        int $walletId,
        string $amount,
        string $referenceId,
        string $type = 'debit',
    ): Transaction {
        return DB::transaction(function () use ($walletId, $amount, $referenceId, $type) {
            $existing = $this->transactionRepository->findByReferenceId($referenceId);
            if ($existing !== null) {
                return $existing;
            }

            $wallet = $this->lockWallet($walletId);

            if ($wallet->is_locked) {
                Log::warning('Debit attempted on locked wallet.', [
                    'wallet_id' => $walletId,
                    'reference_id' => $referenceId,
                    'amount' => $amount,
                ]);
            }

            $this->assertWalletNotLocked($wallet);
            $this->assertSufficientBalance($wallet, $amount);

            $transaction = $this->recordDebit($wallet, $amount, $referenceId, $type);

            event(new WalletDebited($wallet, $transaction));

            return $transaction;
        });
    }

    /**
     * Move funds between two wallets of the same employee (same currency).
     *
     * Employer pool → employee is not supported; use credit() via payroll or a future employer wallet model.
     *
     * @return array{debit: Transaction, credit: Transaction}
     *
     * @throws CrossEmployeeTransferNotAllowedException
     * @throws SameCurrencyRequiredException
     */
    public function transfer(
        int $fromWalletId,
        int $toWalletId,
        string $amount,
        string $referenceId,
    ): array {
        return DB::transaction(function () use ($fromWalletId, $toWalletId, $amount, $referenceId) {
            $debitReferenceId = $this->transferOutReferenceId($referenceId);
            $creditReferenceId = $this->transferInReferenceId($referenceId);

            $existingDebit = $this->transactionRepository->findByReferenceId($debitReferenceId);
            if ($existingDebit !== null) {
                $existingCredit = $this->transactionRepository->findByReferenceId($creditReferenceId);

                if ($existingCredit === null) {
                    throw new \RuntimeException('Transfer debit exists without matching credit.');
                }

                return [
                    'debit' => $existingDebit,
                    'credit' => $existingCredit,
                ];
            }

            $wallets = $this->lockWalletsInOrder($fromWalletId, $toWalletId);
            $fromWallet = $wallets[$fromWalletId];
            $toWallet = $wallets[$toWalletId];

            if ($fromWallet->employee_id !== $toWallet->employee_id) {
                throw new CrossEmployeeTransferNotAllowedException;
            }

            if ($fromWallet->currency !== $toWallet->currency) {
                throw new SameCurrencyRequiredException();
            }

            $this->assertWalletNotLocked($fromWallet);
            $this->assertWalletNotLocked($toWallet);
            $this->assertSufficientBalance($fromWallet, $amount);

            $debitTransaction = $this->recordDebit(
                $fromWallet,
                $amount,
                $debitReferenceId,
                'transfer_out',
            );

            event(new WalletDebited($fromWallet, $debitTransaction));

            $creditTransaction = $this->recordCredit(
                $toWallet,
                $amount,
                $creditReferenceId,
                'transfer_in',
            );

            event(new WalletCredited($toWallet, $creditTransaction));

            return [
                'debit' => $debitTransaction,
                'credit' => $creditTransaction,
            ];
        });
    }

    public function initiateWithdrawal(int $walletId, string $amount): WithdrawalRequest
    {
        return DB::transaction(function () use ($walletId, $amount) {
            $referenceId = 'withdrawal_reserve:'.Str::uuid()->toString();

            $wallet = $this->lockWallet($walletId);
            $this->assertWalletNotLocked($wallet);
            $this->assertSufficientBalance($wallet, $amount);

            $transaction = $this->recordDebit($wallet, $amount, $referenceId, 'withdrawal_reserved');

            event(new WalletDebited($wallet, $transaction));

            $withdrawalRequest = WithdrawalRequest::query()->create([
                'wallet_id' => $walletId,
                'amount' => $amount,
                'status' => 'pending',
                'reserved_at' => now(),
            ]);

            InitiateBankPaymentJob::dispatch($withdrawalRequest)->afterCommit();

            return $withdrawalRequest;
        });
    }

    private function recordCredit(
        Wallet $wallet,
        string $amount,
        string $referenceId,
        string $type,
    ): Transaction {
        $balanceAfter = bcadd($this->balanceAsString($wallet), $amount, 4);
        $this->walletRepository->updateBalance($wallet->id, $balanceAfter);

        return $this->transactionRepository->createForWallet($wallet->id, [
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'reference_id' => $referenceId,
        ]);
    }

    private function recordDebit(
        Wallet $wallet,
        string $amount,
        string $referenceId,
        string $type,
    ): Transaction {
        $balanceAfter = bcsub($this->balanceAsString($wallet), $amount, 4);
        $this->walletRepository->updateBalance($wallet->id, $balanceAfter);

        return $this->transactionRepository->createForWallet($wallet->id, [
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'reference_id' => $referenceId,
        ]);
    }

    private function lockWallet(int $id): Wallet
    {
        try {
            return $this->walletRepository->findByIdWithLock($id);
        } catch (ModelNotFoundException) {
            throw new WalletNotFoundException($id);
        }
    }

    /**
     * @return array<int, Wallet>
     */
    private function lockWalletsInOrder(int ...$walletIds): array
    {
        $uniqueIds = array_values(array_unique($walletIds));
        sort($uniqueIds, SORT_NUMERIC);

        $wallets = [];
        foreach ($uniqueIds as $id) {
            $wallets[$id] = $this->lockWallet($id);
        }

        return $wallets;
    }

    private function assertWalletNotLocked(Wallet $wallet): void
    {
        if ($wallet->is_locked) {
            throw new WalletLockedException($wallet->id);
        }
    }

    private function assertSufficientBalance(Wallet $wallet, string $amount): void
    {
        if (bccomp($this->balanceAsString($wallet), $amount, 4) < 0) {
            throw new InsufficientBalanceException(
                walletId: (string) $wallet->id,
                requested: $amount,
                available: $this->balanceAsString($wallet),
            );
        }
    }

    private function balanceAsString(Wallet $wallet): string
    {
        return bcadd((string) $wallet->balance, '0', 4);
    }

    private function transferOutReferenceId(string $referenceId): string
    {
        return $referenceId.':transfer_out';
    }

    private function transferInReferenceId(string $referenceId): string
    {
        return $referenceId.':transfer_in';
    }
}
