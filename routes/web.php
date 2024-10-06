<?php

use Illuminate\Support\Facades\Route;
use App\Models\Client;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\MachineController;

// Redireciona a rota inicial "/" para o dashboard
Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Protege as rotas com middleware de autenticação e middleware web para sessões
Route::middleware([
    'web', // Inclui o middleware 'web' para garantir que sessões e mensagens flash funcionem corretamente
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {

    // Rota do dashboard que carrega os clientes e suas máquinas
    Route::get('/dashboard', function () {
        $clients = Client::with('machines')->get(); // Carrega clientes com suas máquinas
        return view('dashboard', compact('clients')); // Passa os clientes para a view
    })->name('dashboard');

    // CRUD de Clientes
    // Armazenar novo cliente
    Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');

    // Excluir cliente e todas as suas máquinas
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');

    // CRUD de Máquinas
    // Armazenar nova máquina
    Route::post('/machines', [MachineController::class, 'store'])->name('machines.store');

    // Excluir máquina específica
    Route::delete('/machines/{machine}', [MachineController::class, 'destroy'])->name('machines.destroy');
});

