<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransferRequest;
use App\Http\Resources\TransactionResource;
use App\Http\Responses\ApiResponse;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;

class TransferController extends Controller
{
    public function __construct(
        private WalletService $walletService,
    ) {}

    public function store(StoreTransferRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->walletService->transfer(
            (int) $validated['from_wallet_id'],
            (int) $validated['to_wallet_id'],
            $validated['amount'],
            $validated['reference_id'],
        );

        return ApiResponse::success(
            'Transfer completed successfully.',
            [
                'debit' => (new TransactionResource($result['debit']))->resolve(),
                'credit' => (new TransactionResource($result['credit']))->resolve(),
            ],
        );
    }
}
