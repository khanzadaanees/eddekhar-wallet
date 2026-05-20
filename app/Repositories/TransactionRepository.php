<?php

namespace App\Repositories;

use App\Models\Transaction;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TransactionRepository implements TransactionRepositoryInterface
{
    public function findByReferenceId(string $referenceId): ?Transaction
    {
        return Transaction::query()
            ->where('reference_id', $referenceId)
            ->first();
    }

    public function createForWallet(int $walletId, array $data): Transaction
    {
        return Transaction::query()->create([
            'wallet_id' => $walletId,
            ...$data,
        ]);
    }

    public function paginateByWallet(int $walletId, array $filters): LengthAwarePaginator
    {
        $query = Transaction::query()
            ->where('wallet_id', $walletId)
            ->orderByDesc('created_at');

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->paginate($filters['per_page'] ?? 15);
    }
}
