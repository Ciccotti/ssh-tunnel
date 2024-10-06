<?php

namespace App\Http\Controllers;

use App\Models\ConnectionRequest;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class CloseTunnelController extends Controller
{
    public function close(ConnectionRequest $connectionRequest)
    {
        // Verifica se a conexão está realmente em progresso
        if ($connectionRequest->status != 'in_progress') {
            return response()->json(['success' => false, 'message' => 'Não é possível fechar um túnel que não está em progresso.']);
        }

        // Tenta fechar a porta no firewall
        try {
            $this->closeFirewallPort($connectionRequest->server_port);
        } catch (ProcessFailedException $e) {
            return response()->json(['success' => false, 'message' => 'Erro ao fechar a porta no firewall: ' . $e->getMessage()]);
        }

        // Atualiza o status da solicitação para "completed"
        $connectionRequest->update(['status' => 'completed']);

        return response()->json(['success' => true, 'message' => 'Túnel fechado com sucesso.']);
    }

    private function closeFirewallPort($port)
    {
        $process = new Process(['sudo', 'ufw', 'delete', 'allow', 'from', 'any', 'to', 'any', 'port', $port]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }
}
