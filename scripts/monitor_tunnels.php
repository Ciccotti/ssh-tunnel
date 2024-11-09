<?php

// Carrega o autoload do Composer para iniciar o Laravel
require __DIR__ . '/../vendor/autoload.php';

// Inicializa o framework Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Função para verificar se a porta está aberta usando netcat (nc)
use Illuminate\Support\Facades\Log;
use App\Models\ConnectionRequest;

function isPortOpen($port)
{
    // Executa o comando netcat (nc) e captura tanto a saída padrão quanto a saída de erro
    $command = "nc -zv localhost $port 2>&1"; // Adicionando verbose (-v) e capturando a saída de erro
    $result = shell_exec($command);
    
    Log::info("Comando executado: $command");
    Log::info("Resultado do comando nc para a porta $port: " . $result);

    // Verifica se a string "succeeded" está presente no resultado
    return strpos($result, 'succeeded') !== false;
}

// Função para verificar e atualizar os túneis
function verificarTunnels()
{
    Log::info("Iniciando verificação de túneis...");

    // Busca todos os túneis com status 'pending' ou 'in_progress'
    $tunnels = ConnectionRequest::whereIn('status', ['pending', 'in_progress'])->get();

    foreach ($tunnels as $tunnel) {
        Log::info("Verificando túnel na porta: {$tunnel->server_port} com status: {$tunnel->status}");
        
        if (isPortOpen($tunnel->server_port)) {
            Log::info("A porta {$tunnel->server_port} está aberta.");
            if ($tunnel->status === 'pending') {
                Log::info("Túnel está 'pending', tentando atualizar para 'in_progress'...");
                
                try {
                    // Tenta atualizar o status para 'in_progress'
                    $updateSuccess = $tunnel->update(['status' => 'in_progress']);
                    if ($updateSuccess) {
                        Log::info("Túnel na porta {$tunnel->server_port} foi atualizado com sucesso para 'in_progress'.");
                    } else {
                        Log::error("Erro ao atualizar o túnel na porta {$tunnel->server_port} para 'in_progress'.");
                    }
                } catch (\Exception $e) {
                    Log::error("Erro ao atualizar o status do túnel na porta {$tunnel->server_port}: " . $e->getMessage());
                }
            }
        } else {
            Log::info("A porta {$tunnel->server_port} está fechada.");
            if ($tunnel->status === 'in_progress') {
                // Chama o controlador para fechar o túnel se a conexão foi encerrada
                Log::info("Tentando fechar o túnel na porta {$tunnel->server_port}...");
                try {
                    // Chamando o fechamento com a opção de forçar o fechamento sem verificar o user_id
                    app('App\Http\Controllers\CloseTunnelController')->close(new \Illuminate\Http\Request(), $tunnel->machine_id, true);
                    Log::info("Túnel na porta {$tunnel->server_port} foi fechado com sucesso.");
                } catch (\Exception $e) {
                    Log::error("Erro ao tentar fechar o túnel na porta {$tunnel->server_port}: " . $e->getMessage());
                }
            }
        }
    }
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

