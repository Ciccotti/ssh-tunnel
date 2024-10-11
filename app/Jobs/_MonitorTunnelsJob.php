<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\ConnectionRequest;
use Illuminate\Support\Facades\Log;

class MonitorTunnelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public function handle()
    {
        Log::info("MonitorTunnelsJob iniciado.");

        // Busca todos os túneis com status 'pending' ou 'in_progress'
        $tunnels = ConnectionRequest::whereIn('status', ['pending', 'in_progress'])->get();

        if ($tunnels->isEmpty()) {
            Log::info("Nenhum túnel encontrado com status 'pending' ou 'in_progress'.");
        }

        foreach ($tunnels as $tunnel) {
            Log::info("Verificando túnel na porta {$tunnel->server_port} com status {$tunnel->status}.");

            if ($this->isPortOpen($tunnel->server_port)) {
                Log::info("Porta {$tunnel->server_port} está aberta.");

                if ($tunnel->status === 'pending') {
                    // Se o túnel está pendente e a porta está aberta, atualize para 'in_progress'
                    $tunnel->update(['status' => 'in_progress']);
                    Log::info("Túnel na porta {$tunnel->server_port} atualizado para 'in_progress'.");
                }
            } else {
                Log::info("Porta {$tunnel->server_port} está fechada.");

                if ($tunnel->status === 'in_progress') {
                    // Se o túnel estava ativo, mas a porta agora está fechada, finalize o túnel
                    Log::info("Fechando túnel na porta {$tunnel->server_port}.");
                    app('App\Http\Controllers\CloseTunnelController')->close(new \Illuminate\Http\Request(), $tunnel->machine_id);
                    Log::info("Túnel na porta {$tunnel->server_port} foi fechado.");
                }
            }
        }

        Log::info("MonitorTunnelsJob finalizado.");
    }

    /**
     * Verifica se a porta está aberta usando o comando Netcat (nc).
     */
    private function isPortOpen($port)
    {
        Log::info("Verificando se a porta {$port} está aberta.");
        $result = shell_exec("nc -z localhost $port");
        Log::info("Resultado do comando nc para a porta {$port}: " . ($result ? 'aberta' : 'fechada'));
        return strpos($result, 'succeeded') !== false; // Retorna true se a porta estiver aberta
    }
}

