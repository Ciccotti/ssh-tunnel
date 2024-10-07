<?php

namespace App\Http\Controllers;

use App\Models\ConnectionRequest;
use App\Models\Machine;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class OpenTunnelController extends Controller
{
    public function open(Request $request, Machine $machine)
    {
        // Valida a porta do serviço
        $validated = $request->validate([
            'service_port' => 'required|integer|min:1|max:65535',
        ]);

        // Gera uma porta aleatória entre 40000 e 60000
        $serverPort = $this->generateRandomPort();

        // Verifica se a porta não está sendo usada
        if ($this->isPortInUse($serverPort)) {
            return response()->json(['success' => false, 'message' => 'A porta escolhida já está em uso. Tente novamente.']);
        }

        // Verifica se a porta já não está sendo utilizada em uma conexão
        if (ConnectionRequest::where('server_port', $serverPort)->where('status', 'in_progress')->exists()) {
            return response()->json(['success' => false, 'message' => 'A porta já está sendo usada em outra conexão.']);
        }

        // Cria uma solicitação de conexão com status 'pending'
        $connectionRequest = ConnectionRequest::create([
            'machine_id' => $machine->id,
            'user_id' => auth()->id(),
            'service_port' => $validated['service_port'],
            'server_port' => $serverPort,
            'ip_address' => auth()->user()->external_ip,
            'status' => 'pending',
        ]);

        // Abre a porta no firewall para o IP do usuário
        try {
            $this->openFirewallPort($serverPort, auth()->user()->external_ip);
        } catch (ProcessFailedException $e) {
            return response()->json(['success' => false, 'message' => 'Erro ao abrir a porta no firewall: ' . $e->getMessage()]);
        }

        // Retorna o sucesso com a porta gerada
        return response()->json([
            'success' => true,
            'message' => 'Túnel aberto com sucesso na porta ' . $serverPort,
            'server_port' => $serverPort, // Porta aleatória gerada retornada aqui
        ]);
    }

    // Método para verificar o status do túnel
    public function getTunnelStatus(Machine $machine)
    {
        $connectionRequest = $machine->connectionRequests()->latest()->first();

        if (!$connectionRequest) {
            return response()->json(['status' => 'no_connection']);
        }

        return response()->json(['status' => $connectionRequest->status]);
    }

    private function generateRandomPort()
    {
        return rand(40000, 60000);
    }

    private function isPortInUse($port)
    {
        $process = new Process(['lsof', '-i', ':' . $port]);
        $process->run();
        return $process->isSuccessful();
    }

    private function openFirewallPort($port, $ip)
    {
        $process = new Process(['sudo', 'ufw', 'allow', 'from', $ip, 'to', 'any', 'port', $port]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }
}
