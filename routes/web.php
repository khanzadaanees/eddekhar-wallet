<?php

use App\Stubs\BankStubController;
use App\Stubs\PayrollStubController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('stubs/payroll/trigger', [PayrollStubController::class, 'trigger']);
Route::post('stubs/bank/payments', [BankStubController::class, 'createPayment']);
