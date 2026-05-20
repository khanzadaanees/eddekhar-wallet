<?php

namespace App\Repositories\Contracts;

use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TransactionRepositoryInterface
{
    public function findByReferenceId(string $referenceId): ?Transaction;

    public function createForWallet(int $walletId, array $data): Transaction;

    public function paginateByWallet(int $walletId, array $filters): LengthAwarePaginator;
}
