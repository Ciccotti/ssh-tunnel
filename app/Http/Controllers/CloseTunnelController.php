<?php

namespace App\Http\Controllers;

use App\Models\ConnectionRequest;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Http\Request;

class CloseTunnelController extends Controller
{
    public function close(Request $request, $machineId)
    {
        // Carrega a última requisição de conexão para a máquina especificada que está "in_progress"
        $connectionRequest = ConnectionRequest::where('machine_id', $machineId)
            ->where('status', 'in_progress')
            ->latest()
            ->first();

        if (!$connectionRequest) {
            return response()->json(['success' => false, 'message' => 'Não há túnel ativo para fechar.']);
        }

        // Tenta encerrar o processo e fechar a porta no firewall
        try {
            $this->terminateProcessOnPort($connectionRequest->server_port);
            $this->closeFirewallPort($connectionRequest->server_port, $connectionRequest->ip_address); // Passando IP
        } catch (ProcessFailedException $e) {
            return response()->json(['success' => false, 'message' => 'Erro ao fechar a porta no firewall: ' . $e->getMessage()]);
        }

        // Atualiza o status da solicitação para "completed"
        $connectionRequest->update(['status' => 'completed']);

        return response()->json(['success' => true, 'message' => 'Túnel fechado com sucesso.']);
    }

    /**
     * Método para encerrar o processo que está utilizando a porta.
     */
    private function terminateProcessOnPort($port)
    {
        // Primeiro, encontra o PID do processo rodando na porta
        $findProcess = new Process(['sudo', 'lsof', '-t', '-i', ':' . $port]);
        $findProcess->run();

        if (!$findProcess->isSuccessful()) {
            \Log::info("Nenhum processo encontrado na porta {$port} ou falha ao encontrar o processo.");
            return; // Não lança exceção, apenas registra o fato de não ter encontrado processo
        }

        // Captura o resultado, que deve ser o PID do processo
        $pid = trim($findProcess->getOutput());

        // Se nenhum processo for encontrado, loga e retorna
        if (empty($pid)) {
            \Log::info("Nenhum processo ativo encontrado na porta {$port}.");
            return;
        }

        // Mata o processo com o PID encontrado
        $killProcess = new Process(['sudo', 'kill', $pid]);
        $killProcess->run();

        if (!$killProcess->isSuccessful()) {
            \Log::error("Falha ao matar o processo {$pid} na porta {$port}: " . $killProcess->getErrorOutput());
            throw new ProcessFailedException($killProcess);
        }

        \Log::info("Processo {$pid} na porta {$port} foi encerrado com sucesso.");
    }

    /**
     * Método para fechar a porta no firewall, removendo a regra associada ao IP externo.
     */
    private function closeFirewallPort($port, $ip)
    {
        // Comando para fechar a porta no firewall com base no IP e porta
        $process = new Process(['sudo', 'ufw', 'delete', 'allow', 'from', $ip, 'to', 'any', 'port', $port]);
        $process->run();

        // Verifica se o comando foi executado com sucesso
        if (!$process->isSuccessful()) {
            \Log::error("Falha ao fechar a porta {$port}: " . $process->getErrorOutput());
            throw new ProcessFailedException($process);
        } else {
            \Log::info("Porta {$port} foi fechada com sucesso.");
        }
    }
}

