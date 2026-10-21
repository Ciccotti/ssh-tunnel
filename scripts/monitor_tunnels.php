<?php

// Carrega o autoload do Composer para iniciar o Laravel
require __DIR__ . '/../vendor/autoload.php';

// Inicializa o framework Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Log;
use App\Services\TunnelManager;

// Função para verificar e atualizar os túneis. Cada rodada (TunnelManager::monitor):
// - 'pending' com a porta escutando passa para 'in_progress';
// - 'in_progress' com a porta fechada (cliente desconectou) é fechado como 'completed';
// - 'pending' vencido (config/tunnel.php) é encerrado como 'failed', removendo a regra do ufw.
function verificarTunnels()
{
    app(TunnelManager::class)->monitor();
}

function criarThread()
{
    while (true) {
        $pid = pcntl_fork(); // Cria um processo filho

        if ($pid == -1) {
            Log::error("Erro ao criar thread. Tentando novamente em 5 segundos.");
            sleep(2);
        } elseif ($pid) {
            pcntl_wait($status); // Processo pai: espera o processo filho terminar
            Log::info("Thread finalizada. Reiniciando...");
        } else {
            try {
                while (true) {
                    verificarTunnels(); // Executa a verificação dos túneis
                    sleep(2); // Aguarda 2 segundos antes da próxima verificação
                }
            } catch (\Exception $e) {
                Log::error("Erro no processo de monitoramento: " . $e->getMessage());
                exit(1);
            }
        }
    }
}

// Inicia o processo de monitoramento
criarThread();
