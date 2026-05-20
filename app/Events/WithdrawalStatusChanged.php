<?php

namespace App\Events;

use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WithdrawalStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public WithdrawalRequest $withdrawal,
    ) {}
}
