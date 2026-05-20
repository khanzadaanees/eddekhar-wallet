<?php

namespace App\Events;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WalletCredited
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Wallet $wallet,
        public Transaction $transaction,
    ) {}
}
