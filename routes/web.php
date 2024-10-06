<?php

use Illuminate\Support\Facades\Route;
use App\Models\Client;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\MachineController;

// Redireciona a rota inicial "/" para o dashboard
Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Protege as rotas com middleware de autenticação
Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    
    // Rota do dashboard que carrega os clientes e suas máquinas
    Route::get('/dashboard', function () {
        $clients = Client::with('machines')->get(); // Carrega clientes com suas máquinas
        return view('dashboard', compact('clients')); // Passa os clientes para a view
    })->name('dashboard');

    // Rota para armazenar novos clientes
    Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');

    // Rota para armazenar novas máquinas
    Route::post('/machines', [MachineController::class, 'store'])->name('machines.store');
});

