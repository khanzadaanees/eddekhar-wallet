<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Events\WithdrawalStatusChanged;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\WithdrawalRequest;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankWebhookController extends Controller
{
    public function handle(Request $request, WalletService $walletService): JsonResponse
    {
        $validated = $request->validate([
            'bank_reference_id' => ['required', 'string'],
            'status' => ['required', 'string', 'in:confirmed,failed'],
        ]);

        $withdrawal = WithdrawalRequest::query()
            ->where('bank_reference_id', $validated['bank_reference_id'])
            ->first();

        if ($withdrawal === null) {
            return ApiResponse::error(
                'Withdrawal not found.',
                'WITHDRAWAL_NOT_FOUND',
                404,
            );
        }

        if (in_array($withdrawal->status, ['confirmed', 'failed'], true)) {
            return ApiResponse::success('Already processed.');
        }

        if ($validated['status'] === 'confirmed') {
            $withdrawal->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            event(new WithdrawalStatusChanged($withdrawal->fresh()));

            return ApiResponse::success('Withdrawal confirmed.');
        }

        DB::transaction(function () use ($withdrawal, $walletService) {
            $withdrawal = $withdrawal->fresh();

            if ($withdrawal === null || in_array($withdrawal->status, ['confirmed', 'failed'], true)) {
                return;
            }

            $withdrawal->update([
                'status' => 'failed',
                'failed_at' => now(),
            ]);

            $walletService->credit(
                $withdrawal->wallet_id,
                (string) $withdrawal->amount,
                "withdrawal_refund:{$withdrawal->id}",
                'withdrawal_refund',
            );

            event(new WithdrawalStatusChanged($withdrawal->fresh()));
        });

        return ApiResponse::success('Withdrawal failed and balance refunded.');
    }
}
