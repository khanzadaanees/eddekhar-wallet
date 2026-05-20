<?php

namespace App\Jobs;

use App\Events\WithdrawalStatusChanged;
use App\Exceptions\BankRejectionException;
use App\Models\WithdrawalRequest;
use App\Services\BankService;
use App\Services\WalletService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class InitiateBankPaymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function __construct(
        public WithdrawalRequest $withdrawalRequest,
    ) {}

    public function handle(BankService $bankService, WalletService $walletService): void
    {
        $withdrawal = $this->withdrawalRequest->fresh();

        if ($withdrawal === null || $withdrawal->status !== 'pending') {
            return;
        }

        try {
            $bankService->initiatePayment($withdrawal);
        } catch (BankRejectionException) {
            $this->markFailedAndRefund($walletService, $withdrawal);

            return;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $walletService = app(WalletService::class);
        $withdrawal = $this->withdrawalRequest->fresh();

        if ($withdrawal === null || $withdrawal->status !== 'pending') {
            return;
        }

        $this->markFailedAndRefund($walletService, $withdrawal);
    }

    private function markFailedAndRefund(WalletService $walletService, WithdrawalRequest $withdrawal): void
    {
        DB::transaction(function () use ($walletService, $withdrawal) {
            $withdrawal = $withdrawal->fresh();

            if ($withdrawal === null || $withdrawal->status !== 'pending') {
                return;
            }

            $withdrawal->update([
                'status' => 'failed',
                'failed_at' => now(),
            ]);

            $walletService->credit(
                $withdrawal->wallet_id,
                (string) $withdrawal->amount,
                "withdrawal_refund:{$withdrawal->id}",
                'withdrawal_refund',
            );

            event(new WithdrawalStatusChanged($withdrawal->fresh()));
        });
    }
}
