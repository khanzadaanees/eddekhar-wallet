<?php

namespace App\Repositories\Contracts;

use App\Models\Wallet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface WalletRepositoryInterface
{
    public function findByIdWithLock(int $id): Wallet;

    public function updateBalance(int $id, string $amount): void;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function findByEmployee(int $employeeId, array $filters, int $perPage = 15): LengthAwarePaginator;
}
