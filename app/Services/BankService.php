<?php

namespace App\Services;

use App\Exceptions\BankRejectionException;
use App\Models\WithdrawalRequest;
use App\Support\InternalApplicationRequest;
use Symfony\Component\HttpFoundation\Response;

class BankService
{
    public function initiatePayment(WithdrawalRequest $withdrawal): void
    {
        $withdrawal->loadMissing('wallet');

        $response = InternalApplicationRequest::postJson('/stubs/bank/payments', [
            'withdrawal_id' => $withdrawal->id,
            'wallet_id' => $withdrawal->wallet_id,
            'amount' => (string) $withdrawal->amount,
            'currency' => $withdrawal->wallet->currency,
        ]);

        $body = json_decode($response->getContent(), true) ?? [];

        if ($response->getStatusCode() === Response::HTTP_UNPROCESSABLE_ENTITY
            || ($body['status'] ?? null) === 'rejected') {
            throw new BankRejectionException(
                $body['message'] ?? 'Bank rejected the payment.',
                $withdrawal->id,
            );
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new \RuntimeException(
                'Bank stub returned HTTP '.$response->getStatusCode().': '.$response->getContent(),
            );
        }

        $bankReferenceId = $body['bank_reference_id'] ?? null;

        if (! $bankReferenceId) {
            throw new \RuntimeException('Bank response missing bank_reference_id.');
        }

        $withdrawal->update([
            'bank_reference_id' => $bankReferenceId,
        ]);
    }
}
