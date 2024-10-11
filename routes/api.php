<?php

use App\Http\Controllers\CheckTunnelRequestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Rota para obter informa��es do usu�rio autenticado (exemplo existente)
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Rota para verificar solicita��es de t�nel para uma m�quina com base no Hardware ID
Route::post('/check-tunnel-request', [CheckTunnelRequestController::class, 'check'])->name('check-tunnel-request');
