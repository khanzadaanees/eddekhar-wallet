<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankRejectionException extends Exception
{
    public function __construct(
        string $message = 'Bank rejected the payment.',
        public readonly ?int $withdrawalId = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function render(Request $request): JsonResponse
    {
        return ApiResponse::error(
            $this->getMessage(),
            'BANK_REJECTION',
            422,
        );
    }
}
