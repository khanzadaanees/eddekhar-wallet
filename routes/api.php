<?php

use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\Webhooks\BankWebhookController;
use App\Http\Controllers\Api\V1\Webhooks\PayrollWebhookController;
use App\Http\Controllers\Api\V1\WithdrawalController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('webhooks/payroll', [PayrollWebhookController::class, 'handle']);
    Route::post('webhooks/bank', [BankWebhookController::class, 'handle']);

    Route::apiResource('employees', EmployeeController::class)->only(['index', 'store', 'show']);

    Route::get('employees/{employee}/wallets', [WalletController::class, 'index']);
    Route::post('employees/{employee}/wallets', [WalletController::class, 'store']);
    Route::get('wallets/{wallet}', [WalletController::class, 'show']);

    Route::get('wallets/{wallet}/transactions', [TransactionController::class, 'index']);

    Route::post('transfers', [TransferController::class, 'store']);

    Route::post('wallets/{wallet}/withdrawals', [WithdrawalController::class, 'store']);
    Route::get('withdrawals/{withdrawal}', [WithdrawalController::class, 'show']);
});
