<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletNotFoundException extends Exception
{
    public function __construct(
        public readonly ?int $walletId = null,
    ) {
        parent::__construct('Wallet not found.');
    }

    public function render(Request $request): JsonResponse
    {
        return ApiResponse::error(
            'Wallet not found.',
            'WALLET_NOT_FOUND',
            404,
        );
    }
}
