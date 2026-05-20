<?php

namespace App\Http\Resources;

use App\Support\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Wallet */
class WalletResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'type' => $this->type,
            'currency' => $this->currency,
            'balance' => MoneyFormatter::format($this->balance),
            'is_locked' => (bool) $this->is_locked,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
