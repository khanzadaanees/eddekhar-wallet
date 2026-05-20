<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsufficientBalanceException extends Exception
{
    public function __construct(
        private readonly string $walletId,
        private readonly string $requested,
        private readonly string $available,
    ) {
        parent::__construct('Insufficient wallet balance.');
    }

    public function render(Request $request): JsonResponse
    {
        return ApiResponse::error(
            'Insufficient wallet balance.',
            'INSUFFICIENT_BALANCE',
            422,
            [
                'amount' => [
                    "Requested amount ({$this->requested}) exceeds available balance ({$this->available}).",
                ],
            ],
        );
    }
}
