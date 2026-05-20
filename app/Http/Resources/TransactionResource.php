<?php

namespace App\Http\Resources;

use App\Support\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Transaction */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, string>
     */
    private static function descriptions(): array
    {
        return [
            'credit' => 'Credit',
            'debit' => 'Debit',
            'transfer_in' => 'Transfer received',
            'transfer_out' => 'Transfer sent',
            'withdrawal_reserved' => 'Withdrawal reserved',
            'withdrawal_refund' => 'Withdrawal refund',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'wallet_id' => $this->wallet_id,
            'type' => $this->type,
            'description' => self::descriptions()[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type)),
            'amount' => MoneyFormatter::format($this->amount),
            'balance_after' => MoneyFormatter::format($this->balance_after),
            'reference_id' => $this->reference_id,
            'reference_type' => $this->reference_type,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
