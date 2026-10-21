<?php

use Illuminate\Support\Facades\Route;
use App\Models\Client;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\OpenTunnelController;
use App\Http\Controllers\CloseTunnelController;

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
        // Carrega apenas os clientes (e máquinas) do usuário logado
        $clients = Client::with('machines')->where('user_id', auth()->id())->get();
        return view('dashboard', compact('clients')); // Passa os clientes para a view
    })->name('dashboard');

    // CRUD de Clientes
    // Armazenar novo cliente
    Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');

    // Excluir cliente e todas as suas máquinas
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy')->can('manage', 'client');

    // CRUD de Máquinas
    // Armazenar nova máquina
    Route::post('/machines', [MachineController::class, 'store'])->name('machines.store');

    // Excluir máquina específica
    Route::delete('/machines/{machine}', [MachineController::class, 'destroy'])->name('machines.destroy')->can('manage', 'machine');

    // Rotas para abertura e fechamento de túnel
    // Rota para abrir o túnel de uma máquina
    Route::post('/machines/{machine}/open-tunnel', [OpenTunnelController::class, 'open'])->name('machines.open-tunnel')->can('manage', 'machine');

    // Rota para fechar o túnel de uma máquina
    Route::post('/machines/{machine}/close-tunnel', [CloseTunnelController::class, 'close'])->name('machines.close-tunnel');

    // Rota para verificar o status do túnel de uma máquina
    Route::get('/machines/{machine}/tunnel-status', [OpenTunnelController::class, 'getTunnelStatus'])->name('machines.tunnel-status')->can('manage', 'machine');
    
    Route::get('/machines/{machine}/check-tunnel', [OpenTunnelController::class, 'checkAndSetInProgress'])->name('machines.check-tunnel')->can('manage', 'machine');

});
