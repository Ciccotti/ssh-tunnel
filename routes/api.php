<?php

use App\Http\Controllers\CheckTunnelRequestController;
use App\Http\Controllers\EnrollmentController;
use Illuminate\Support\Facades\Route;

// Rota para verificar solicitações de túnel para uma máquina com base no Hardware ID.
// Protegida por rate limit (throttle) para mitigar abuso/enumeração de hardware IDs.
Route::middleware('throttle:60,1')
    ->post('/check-tunnel-request', [CheckTunnelRequestController::class, 'check'])
    ->name('check-tunnel-request');

// Registro da máquina pelo instalador do cliente (código de uso único gerado no painel)
Route::middleware('throttle:10,1')
    ->post('/enroll', [EnrollmentController::class, 'enroll'])
    ->name('enroll');
