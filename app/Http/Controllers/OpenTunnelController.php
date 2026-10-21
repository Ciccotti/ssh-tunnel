<?php

namespace App\Http\Controllers;

use App\Models\ConnectionRequest;
use App\Models\Machine;
use App\Services\TunnelManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OpenTunnelController extends Controller
{
    public function __construct(private TunnelManager $tunnels)
    {
    }

    // Função para abrir o túnel
    public function open(Request $request, Machine $machine)
    {
        // Valida a porta do serviço
        $validated = $request->validate([
            'service_port' => 'required|integer|min:1|max:65535',
        ]);

        // Captura o IP do usuário da requisição (pode ser alterado para auth()->user()->external_ip se o IP for armazenado na tabela de usuários)
        $userIp = $request->ip();

        // A trava cobre o intervalo entre escolher a porta e gravar a solicitação,
        // para que duas solicitações simultâneas não fiquem com a mesma porta.
        try {
            $connectionRequest = Cache::lock('tunnel-port-allocation', 30)->block(10, function () use ($machine, $validated, $userIp) {
                // Sorteia uma porta livre no sistema e sem solicitação ativa no banco
                $serverPort = $machine->getRandomAvailablePort();

                if (!$serverPort) {
                    return null;
                }

                // Adiciona a regra no firewall para permitir a porta só para o IP do usuário
                $this->tunnels->openFirewallPort($serverPort, $userIp);

                // Cria uma solicitação de conexão com status 'pending'
                return ConnectionRequest::create([
                    'machine_id' => $machine->id,
                    'user_id' => auth()->id(),
                    'service_port' => $validated['service_port'],
                    'server_port' => $serverPort,
                    'ip_address' => $userIp, // Salva o IP do usuário
                    'status' => 'pending', // O status inicial é 'pending'
                ]);
            });
        } catch (LockTimeoutException $e) {
            return response()->json(['success' => false, 'message' => 'O servidor está ocupado alocando outra porta. Tente novamente.']);
        } catch (ProcessFailedException $e) {
            Log::error('Erro ao abrir a porta no firewall: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erro ao abrir a porta no firewall.']);
        }

        if (!$connectionRequest) {
            return response()->json(['success' => false, 'message' => 'Nenhuma porta disponível no momento. Tente novamente.']);
        }

        $serverPort = $connectionRequest->server_port;

        Log::info("Solicitação de conexão criada para máquina {$machine->id} na porta {$serverPort}");

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
        $connectionRequest = $machine->connectionRequests()->latest('id')->first();

        if (!$connectionRequest) {
            return response()->json(['status' => 'no_connection']);
        }

        // Se a solicitação pendente já tem o túnel escutando na porta, muda o status para 'in_progress'
        if ($connectionRequest->status === 'pending' && $this->tunnels->isListening($connectionRequest->server_port)) {
            $connectionRequest->update(['status' => 'in_progress']);
        }

        return response()->json(['status' => $connectionRequest->status]);
    }

    // Função para verificar o status do túnel de uma máquina
    public function getTunnelStatus(Machine $machine)
    {
        $connectionRequest = $machine->connectionRequests()->latest('id')->first();

        if (!$connectionRequest) {
            return response()->json(['status' => 'no_connection']);
        }

        return response()->json([
            'status' => $connectionRequest->status,
            'server_port' => $connectionRequest->server_port,
        ]);
    }
}
