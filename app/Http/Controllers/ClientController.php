<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\TunnelManager;
use Illuminate\Http\Request;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ClientController extends Controller
{
    // Função para armazenar um novo cliente
    public function store(Request $request)
    {
        // Valida os dados recebidos
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        // Cria o novo cliente
        Client::create([
            'user_id' => Auth::id(), // Define o usuário logado como o criador do cliente
            'name' => $validated['name'],
        ]);

        // Redireciona de volta ao dashboard com uma mensagem de sucesso
        return redirect()->route('dashboard')->with('success', 'Cliente cadastrado com sucesso!');
    }

    // Função para deletar um cliente e todas as suas máquinas
    public function destroy(Client $client, TunnelManager $tunnels)
    {
        // Fecha os túneis ativos e pendentes das máquinas antes: a exclusão apaga as
        // solicitações em cascata, mas não remove a regra do firewall nem encerra o túnel.
        try {
            foreach ($client->connectionRequests()->active()->get() as $connectionRequest) {
                $tunnels->close($connectionRequest);
            }
        } catch (ProcessFailedException $e) {
            Log::error("Erro ao fechar os túneis do cliente {$client->id} antes de excluí-lo: " . $e->getMessage());
            return redirect()->route('dashboard')->with('error', 'Não foi possível fechar os túneis das máquinas do cliente, então ele não foi excluído. Tente novamente.');
        }

        // Exclui todas as máquinas associadas ao cliente
        $client->machines()->delete();

        // Exclui o cliente
        $client->delete();

        // Redireciona de volta ao dashboard com uma mensagem de sucesso
        return redirect()->route('dashboard')->with('success', 'Cliente e suas máquinas foram excluídos com sucesso!');
    }
}
