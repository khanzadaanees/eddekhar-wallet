<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'type' => 'salary',
            'currency' => 'SAR',
            'balance' => '0.0000',
            'is_locked' => false,
        ];
    }

    public function savings(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'savings',
        ]);
    }

    public function withBalance(string $balance): static
    {
        return $this->state(fn (array $attributes) => [
            'balance' => $balance,
        ]);
    }
}
