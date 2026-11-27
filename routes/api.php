<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DepositController;
use App\Http\Controllers\Api\V1\WithdrawController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('auth.login');

Route::post('/transactions/deposit', [DepositController::class, 'store'])
    ->middleware(['auth.jwt', 'idempotency:deposit'])
    ->name('transactions.deposit');

Route::post('/transactions/withdraw', [WithdrawController::class, 'store'])
    ->middleware(['auth.jwt', 'idempotency:withdraw'])
    ->name('transactions.withdraw');
