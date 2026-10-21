<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Services\TunnelManager;
use Illuminate\Http\Request;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Log;

class CloseTunnelController extends Controller
{
    // Fecha o túnel ativo da máquina ou cancela a solicitação que ainda está pendente.
    // O fechamento automático (cliente desconectou ou prazo vencido) fica no monitor,
    // que usa o TunnelManager diretamente.
    public function close(Request $request, Machine $machine, TunnelManager $tunnels)
    {
        Log::info("Iniciando fechamento do túnel para a máquina {$machine->id}");

        $connectionRequest = $machine->connectionRequests()
            ->active()
            ->where('user_id', auth()->id()) // Garante que o usuário que criou está fechando
            ->latest('id')
            ->first();

        if (!$connectionRequest) {
            Log::warning("Nenhum túnel ativo encontrado para a máquina {$machine->id}");
            return response()->json(['success' => false, 'message' => 'Não há túnel ativo para fechar ou você não tem permissão.']);
        }

        // Uma solicitação cancelada antes de a máquina cliente conectar fica como 'failed'
        $isPending = $connectionRequest->status === 'pending';
        $finalStatus = $isPending ? 'failed' : 'completed';

        // Encerra o processo do túnel, remove a regra do firewall e grava o status final
        try {
            $tunnels->close($connectionRequest, $finalStatus);
        } catch (ProcessFailedException $e) {
            // O texto da exceção traz a saída dos comandos; fica só no log.
            Log::error("Erro ao fechar o túnel na porta {$connectionRequest->server_port}: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erro ao fechar o túnel. Tente novamente.']);
        }

        return response()->json([
            'success' => true,
            'message' => $isPending ? 'Solicitação cancelada.' : 'Túnel fechado com sucesso.',
            'status' => $finalStatus,
        ]);
    }
}
