<?php

namespace App\Http\Controllers;

use App\Models\ConnectionRequest;
use App\Models\Machine;
use Illuminate\Http\Request;

class OpenTunnelController extends Controller
{
    // Função para abrir o túnel
    public function open(Request $request, Machine $machine)
    {
        // Valida a porta do serviço
        $validated = $request->validate([
            'service_port' => 'required|integer|min:1|max:65535',
        ]);

        // Gera uma porta aleatória entre 40000 e 60000
        $serverPort = $this->generateRandomPort();

        // Verifica se a porta aleatória está disponível
        if ($this->isPortInUse($serverPort)) {
            return response()->json(['success' => false, 'message' => 'A porta escolhida já está em uso. Tente novamente.']);
        }

        // Cria uma solicitação de conexão com status 'pending'
        $connectionRequest = ConnectionRequest::create([
            'machine_id' => $machine->id,
            'user_id' => auth()->id(),
            'service_port' => $validated['service_port'],
            'server_port' => $serverPort,
            'ip_address' => auth()->user()->external_ip,
            'status' => 'pending', // O status inicial é 'pending'
        ]);

        // Retorna a resposta inicial com o status 'pending' e a porta gerada
        return response()->json([
            'success' => true,
            'message' => 'Solicitação de abertura de túnel criada com sucesso. Porta alocada: ' . $serverPort,
            'server_port' => $serverPort,
            'status' => 'pending',
        ]);
    }

    // Método para verificar e atualizar o status do túnel
    public function checkAndSetInProgress(Machine $machine)
    {
        $connectionRequest = $machine->connectionRequests()->latest()->first();

        if (!$connectionRequest) {
            return response()->json(['status' => 'no_connection']);
        }

        // Verifica se a porta está sendo usada e muda o status para 'in_progress'
        if ($this->isPortInUse($connectionRequest->server_port)) {
            $connectionRequest->update(['status' => 'in_progress']);
            return response()->json(['status' => 'in_progress']);
        }

        // Caso a porta ainda não esteja em uso
        return response()->json(['status' => 'pending']);
    }

    // Função para verificar o status do túnel de uma máquina
    public function getTunnelStatus(Machine $machine)
    {
        $connectionRequest = $machine->connectionRequests()->latest()->first();

        if (!$connectionRequest) {
            return response()->json(['status' => 'no_connection']);
        }

        return response()->json(['status' => $connectionRequest->status]);
    }

    // Funções auxiliares...
    private function generateRandomPort()
    {
        return rand(40000, 60000);
    }

    // Função para verificar se a porta está em uso (ajustada para verificar dinamicamente)
    private function isPortInUse($port)
    {
        // Comando para verificar se a porta está em uso
        $output = shell_exec("lsof -i :$port"); // Verifica se a porta está aberta
        return !empty($output); // Retorna true se a porta estiver em uso
    }
}
