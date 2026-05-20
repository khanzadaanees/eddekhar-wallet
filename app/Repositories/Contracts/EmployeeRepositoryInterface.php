<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface EmployeeRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function findWithFilters(array $filters, int $perPage = 15): LengthAwarePaginator;
}
