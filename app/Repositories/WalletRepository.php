<?php

namespace App\Repositories;

use App\Models\Wallet;
use App\Repositories\Contracts\WalletRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class WalletRepository implements WalletRepositoryInterface
{
    public function findByIdWithLock(int $id): Wallet
    {
        return Wallet::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function updateBalance(int $id, string $amount): void
    {
        Wallet::query()
            ->whereKey($id)
            ->update(['balance' => $amount]);
    }

    public function findByEmployee(int $employeeId, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = Wallet::query()->where('employee_id', $employeeId);

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        if (array_key_exists('is_locked', $filters)) {
            $query->where('is_locked', (bool) $filters['is_locked']);
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }
}
