<?php

namespace App\Listeners;

use App\Events\WalletCredited;
use App\Events\WalletDebited;
use Illuminate\Support\Facades\Log;

class LogWalletActivity
{
    public function handle(WalletCredited|WalletDebited $event): void
    {
        $transaction = $event->transaction;

        Log::info($event instanceof WalletCredited ? 'Wallet credited' : 'Wallet debited', [
            'wallet_id' => $event->wallet->id,
            'transaction_id' => $transaction->id,
            'amount' => (string) $transaction->amount,
            'type' => $transaction->type,
            'balance_after' => (string) $transaction->balance_after,
        ]);
    }
}
