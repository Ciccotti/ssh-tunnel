<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\ConnectionRequest;
use Illuminate\Http\Request;

class CheckTunnelRequestController extends Controller
{
    public function check(Request $request)
    {
        // Captura o hardware_id do corpo da requisição
        $hardwareId = $request->input('hardware_id');

        // Verifica se o hardware_id foi enviado
        if (!$hardwareId) {
            return response()->json(['success' => false, 'message' => 'Hardware ID não encontrado no corpo da requisição.'], 400);
        }

        // Verifica se existe uma máquina com o hardware_id informado
        $machine = Machine::where('hardware_id', $hardwareId)->first();

        if (!$machine) {
            return response()->json(['success' => false, 'message' => 'Máquina não encontrada.'], 404);
        }

        // Busca a requisição de conexão mais antiga com status 'pending'
        $connectionRequest = ConnectionRequest::where('machine_id', $machine->id)
            ->where('status', 'pending')
            ->oldest()
            ->first();

        if (!$connectionRequest) {
            // Resposta fixa quando não há requisição
            return response()->json(['success' => false, 'message' => 'Nenhuma solicitação de túnel pendente.']);
        }

        // Resposta com as portas se houver uma solicitação pendente
        return response()->json([
            'success' => true,
            'service_port' => $connectionRequest->service_port,
            'server_port' => $connectionRequest->server_port,
            'status' => $connectionRequest->status,
        ]);
    }
}

