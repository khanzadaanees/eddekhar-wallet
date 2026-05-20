<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexWalletRequest;
use App\Http\Requests\StoreWalletRequest;
use App\Http\Resources\WalletResource;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Models\Wallet;
use App\Repositories\Contracts\WalletRepositoryInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
    ) {}

    public function index(IndexWalletRequest $request, Employee $employee): JsonResponse
    {
        $wallets = $this->walletRepository->findByEmployee(
            employeeId: $employee->id,
            filters: $request->validated(),
            perPage: $request->integer('per_page', 15),
        );

        return ApiResponse::paginated(
            'Wallets retrieved successfully.',
            $wallets,
            WalletResource::collection($wallets),
        );
    }

    public function store(StoreWalletRequest $request, Employee $employee): JsonResponse
    {
        try {
            $wallet = DB::transaction(function () use ($request, $employee) {
                $validated = $request->validated();

                return Wallet::query()->create([
                    'employee_id' => $employee->id,
                    'type' => $validated['type'],
                    'currency' => $validated['currency'],
                    'balance' => '0.0000',
                    'is_locked' => false,
                ]);
            });
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                return ApiResponse::error(
                    'This employee already has a wallet of this type.',
                    'WALLET_TYPE_EXISTS',
                    422,
                );
            }

            throw $e;
        }

        return ApiResponse::success(
            'Wallet created successfully.',
            new WalletResource($wallet),
            201,
        );
    }

    public function show(Wallet $wallet): JsonResponse
    {
        $wallet->refresh();

        return ApiResponse::success(
            'Wallet retrieved successfully.',
            new WalletResource($wallet),
        );
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');

        if (in_array($sqlState, ['23505', '23000'], true)) {
            return true;
        }

        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $driverCode === 1062 || $driverCode === 19;
    }
}
