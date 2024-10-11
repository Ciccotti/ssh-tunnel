<?php

namespace App\Http\Controllers;

use App\Models\ConnectionRequest;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CloseTunnelController extends Controller
{
    public function close(Request $request, $machineId, $forceClose = false)
    {
        Log::info("Iniciando fechamento do túnel para a máquina {$machineId}");

        // Verifica se o fechamento está sendo forçado (pelo monitoramento)
        if ($forceClose) {
            $connectionRequest = ConnectionRequest::where('machine_id', $machineId)
                ->where('status', 'in_progress')
                ->latest()
                ->first();
        } else {
            // Verifica se o fechamento está sendo feito por um usuário autenticado
            $connectionRequest = ConnectionRequest::where('machine_id', $machineId)
                ->where('status', 'in_progress')
                ->where('user_id', auth()->id()) // Garante que o usuário que criou está fechando
                ->latest()
                ->first();
        }

        if (!$connectionRequest) {
            Log::warning("Nenhum túnel ativo encontrado para a máquina {$machineId}");
            return response()->json(['success' => false, 'message' => 'Não há túnel ativo para fechar ou você não tem permissão.']);
        }

        Log::info("Túnel encontrado na porta {$connectionRequest->server_port} para fechamento.");

        // Tenta encerrar o processo e fechar a porta no firewall
        try {
            $this->terminateProcessOnPort($connectionRequest->server_port);
            $this->closeFirewallPort($connectionRequest->server_port, $connectionRequest->ip_address);
        } catch (ProcessFailedException $e) {
            Log::error("Erro ao fechar o túnel na porta {$connectionRequest->server_port}: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erro ao fechar a porta no firewall: ' . $e->getMessage()]);
        }

        // Atualiza o status da solicitação para "completed"
        $connectionRequest->update(['status' => 'completed']);
        Log::info("Túnel na porta {$connectionRequest->server_port} foi fechado e status atualizado para 'completed'.");

        return response()->json(['success' => true, 'message' => 'Túnel fechado com sucesso.']);
    }

    private function terminateProcessOnPort($port)
    {
        Log::info("Tentando encerrar o processo na porta {$port}");

        $findProcess = new Process(['sudo', 'lsof', '-t', '-i', ':' . $port]);
        $findProcess->run();

        if (!$findProcess->isSuccessful() || empty($findProcess->getOutput())) {
            Log::info("Nenhum processo encontrado na porta {$port}. Prosseguindo para fechar a regra no firewall.");
            return;
        }

        $pid = trim($findProcess->getOutput());

        $killProcess = new Process(['sudo', 'kill', $pid]);
        $killProcess->run();

        if (!$killProcess->isSuccessful()) {
            Log::error("Falha ao matar o processo {$pid} na porta {$port}: " . $killProcess->getErrorOutput());
            throw new ProcessFailedException($killProcess);
        }

        Log::info("Processo {$pid} na porta {$port} foi encerrado com sucesso.");
    }

    private function closeFirewallPort($port, $ipAddress)
    {
        Log::info("Tentando fechar a porta {$port} no firewall para o IP {$ipAddress}");

        $process = new Process(['sudo', 'ufw', 'delete', 'allow', 'from', $ipAddress, 'to', 'any', 'port', $port]);
        $process->run();

        if (!$process->isSuccessful()) {
            Log::error("Falha ao fechar a porta {$port} no firewall para o IP {$ipAddress}: " . $process->getErrorOutput());
            throw new ProcessFailedException($process);
        }

        Log::info("Porta {$port} para o IP {$ipAddress} foi fechada com sucesso no firewall.");
    }
}
