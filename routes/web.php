<?php

use Illuminate\Support\Facades\Route;
use App\Models\Client;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\OpenTunnelController;
use App\Http\Controllers\CloseTunnelController;
use App\Http\Controllers\EnrollmentController;

// Redireciona a rota inicial "/" para o dashboard
Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Instalação do cliente com um comando (públicas: o código de uso único vai na URL)
Route::get('/instalar/cliente.py', [EnrollmentController::class, 'clientScript'])->name('install.client');

Route::get('/instalar/{token}/{os}', [EnrollmentController::class, 'script'])
    ->where(['token' => '[A-Za-z0-9]{32}', 'os' => 'windows|linux'])
    ->middleware('throttle:30,1')
    ->name('install.script');

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

    // Instalar em Nova Máquina: gera o comando de instalação e acompanha o seu uso
    Route::post('/clients/{client}/enrollment-tokens', [EnrollmentController::class, 'store'])->name('enrollment-tokens.store')->can('manage', 'client');
    Route::get('/enrollment-tokens/{enrollmentToken}', [EnrollmentController::class, 'status'])->name('enrollment-tokens.status')->can('manage', 'enrollmentToken');

    // CRUD de Máquinas
    // Armazenar nova máquina
    Route::post('/machines', [MachineController::class, 'store'])->name('machines.store');

    // Excluir máquina específica
    Route::delete('/machines/{machine}', [MachineController::class, 'destroy'])->name('machines.destroy')->can('manage', 'machine');

    // Rotas para abertura e fechamento de túnel
    // Rota para abrir o túnel de uma máquina
    Route::post('/machines/{machine}/open-tunnel', [OpenTunnelController::class, 'open'])->name('machines.open-tunnel')->can('manage', 'machine');

    // Rota para fechar o túnel de uma máquina (ou cancelar a solicitação pendente)
    Route::post('/machines/{machine}/close-tunnel', [CloseTunnelController::class, 'close'])->name('machines.close-tunnel')->can('manage', 'machine');

    // Rota para verificar o status do túnel de uma máquina
    Route::get('/machines/{machine}/tunnel-status', [OpenTunnelController::class, 'getTunnelStatus'])->name('machines.tunnel-status')->can('manage', 'machine');

});
