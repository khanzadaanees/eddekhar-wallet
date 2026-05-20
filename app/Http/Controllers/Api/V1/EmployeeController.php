<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexEmployeeRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Models\Wallet;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class EmployeeController extends Controller
{
    public function __construct(
        private EmployeeRepositoryInterface $employeeRepository,
    ) {}

    public function index(IndexEmployeeRequest $request): JsonResponse
    {
        $employees = $this->employeeRepository->findWithFilters(
            filters: $request->validated(),
            perPage: $request->integer('per_page', 15),
        );

        return ApiResponse::paginated(
            'Employees retrieved successfully.',
            $employees,
            EmployeeResource::collection($employees),
        );
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = DB::transaction(function () use ($request) {
            $validated = $request->validated();

            $employee = Employee::query()->create([
                'external_id' => $validated['external_id'],
                'name' => $validated['name'],
                'email' => $validated['email'],
                'company_id' => $validated['company_id'],
                'status' => $validated['status'] ?? Employee::STATUS_ACTIVE,
            ]);

            Wallet::query()->create([
                'employee_id' => $employee->id,
                'type' => Wallet::TYPE_SALARY,
                'currency' => $validated['currency'] ?? 'SAR',
                'balance' => '0.0000',
                'is_locked' => false,
            ]);

            return $employee->load('wallets');
        });

        return ApiResponse::success(
            'Employee created successfully.',
            new EmployeeResource($employee),
            201,
        );
    }

    public function show(Employee $employee): JsonResponse
    {
        $employee->load('wallets');

        return ApiResponse::success(
            'Employee retrieved successfully.',
            new EmployeeResource($employee),
        );
    }
}
