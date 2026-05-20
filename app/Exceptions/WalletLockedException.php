<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletLockedException extends Exception
{
    public function __construct(
        public readonly ?int $walletId = null,
    ) {
        parent::__construct('Wallet is currently locked.');
    }

    public function render(Request $request): JsonResponse
    {
        return ApiResponse::error(
            'Wallet is currently locked.',
            'WALLET_LOCKED',
            423,
        );
    }
}
