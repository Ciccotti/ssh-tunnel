<?php

use App\Http\Controllers\CheckTunnelRequestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Rota para obter informações do usuário autenticado (exemplo existente)
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Rota para verificar solicitações de túnel para uma máquina com base no Hardware ID.
// Protegida por rate limit (throttle) para mitigar abuso/enumeração de hardware IDs.
Route::middleware('throttle:60,1')
    ->post('/check-tunnel-request', [CheckTunnelRequestController::class, 'check'])
    ->name('check-tunnel-request');
