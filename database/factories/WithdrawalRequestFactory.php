<?php

namespace Database\Factories;

use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WithdrawalRequest>
 */
class WithdrawalRequestFactory extends Factory
{
    protected $model = WithdrawalRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wallet_id' => Wallet::factory(),
            'amount' => '100.0000',
            'status' => 'pending',
            'bank_reference_id' => null,
            'reserved_at' => now(),
            'confirmed_at' => null,
            'failed_at' => null,
        ];
    }
}
