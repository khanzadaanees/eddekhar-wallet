<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SameCurrencyRequiredException extends Exception
{
    public function __construct()
    {
        parent::__construct('Both wallets must use the same currency.');
    }

    public function render(Request $request): JsonResponse
    {
        return ApiResponse::error(
            'Both wallets must use the same currency.',
            'CURRENCY_MISMATCH',
            422,
        );
    }
}
