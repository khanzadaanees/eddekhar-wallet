<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWithdrawalRequest;
use App\Http\Resources\WithdrawalResource;
use App\Http\Responses\ApiResponse;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;

class WithdrawalController extends Controller
{
    public function __construct(
        private WalletService $walletService,
    ) {}

    public function store(StoreWithdrawalRequest $request, Wallet $wallet): JsonResponse
    {
        $withdrawal = $this->walletService->initiateWithdrawal(
            $wallet->id,
            $request->validated('amount'),
        );

        return ApiResponse::success(
            'Withdrawal initiated successfully.',
            new WithdrawalResource($withdrawal),
            201,
        );
    }

    public function show(WithdrawalRequest $withdrawal): JsonResponse
    {
        return ApiResponse::success(
            'Withdrawal retrieved successfully.',
            new WithdrawalResource($withdrawal->fresh()),
        );
    }
}
