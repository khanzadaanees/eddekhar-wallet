<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrossEmployeeTransferNotAllowedException extends Exception
{
    public function __construct()
    {
        parent::__construct(
            'Transfers are only allowed between wallets of the same employee. '
            .'Employer pool funding uses payroll credit, not transfer.',
        );
    }

    public function render(Request $request): JsonResponse
    {
        return ApiResponse::error(
            $this->getMessage(),
            'CROSS_EMPLOYEE_TRANSFER_NOT_ALLOWED',
            422,
        );
    }
}
