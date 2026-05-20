<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Wallet;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    public function __construct(
        private TransactionRepositoryInterface $transactionRepository,
    ) {}

    public function index(Request $request, Wallet $wallet): JsonResponse
    {
        $request->validate([
            'type' => ['sometimes', 'string', Rule::in([
                'credit',
                'debit',
                'transfer_in',
                'transfer_out',
                'withdrawal_reserved',
                'withdrawal_refund',
            ])],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $transactions = $this->transactionRepository->paginateByWallet($wallet->id, [
            'type' => $request->input('type'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'per_page' => $request->integer('per_page', 15),
        ]);

        return ApiResponse::paginated(
            'Transactions retrieved successfully.',
            $transactions,
            TransactionResource::collection($transactions),
        );
    }
}
