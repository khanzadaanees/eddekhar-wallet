<?php

namespace App\Stubs;

use App\Http\Controllers\Controller;
use App\Jobs\SendBankWebhookCallbackJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class BankStubController extends Controller
{
    public function createPayment(Request $request): JsonResponse
    {
        $request->validate([
            'withdrawal_id' => ['required', 'integer'],
            'wallet_id' => ['required', 'integer'],
            'amount' => ['required', 'string'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        if (random_int(1, 100) <= 20) {
            return response()->json([
                'status' => 'rejected',
                'message' => 'Payment rejected by bank stub.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $bankReferenceId = 'bank_'.Str::uuid()->toString();

        SendBankWebhookCallbackJob::dispatch($bankReferenceId, 'confirmed')
            ->delay(now()->addSeconds(3));

        return response()->json([
            'status' => 'accepted',
            'bank_reference_id' => $bankReferenceId,
        ]);
    }
}
