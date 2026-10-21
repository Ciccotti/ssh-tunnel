<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Services\TunnelManager;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class MachineController extends Controller
{
    // Função para armazenar uma nova máquina
    public function store(Request $request)
    {
        // Valida os dados recebidos
        $validated = $request->validate([
            // O cliente precisa existir e pertencer ao usuário logado
            'client_id' => ['required', Rule::exists('clients', 'id')->where('user_id', auth()->id())],
            'name' => 'required|string|max:255',
            'specifications' => 'nullable|string',
            'hardware_id' => 'required|string|max:255|unique:machines,hardware_id',
        ]);

        try {
            // Cria a nova máquina
            Machine::create([
                'client_id' => $validated['client_id'],
                'name' => $validated['name'],
                'specifications' => $validated['specifications'] ?? null,
                'hardware_id' => $validated['hardware_id'],
            ]);

            // Redireciona de volta ao dashboard com uma mensagem de sucesso
            return redirect()->route('dashboard')->with('success', 'Máquina cadastrada com sucesso!');

        } catch (QueryException $e) {
            // Captura o erro de chave duplicada e redireciona com uma mensagem de erro
            if ($e->errorInfo[1] == 1062) { // Código de erro para violação de chave única (MySQL-specific)
                return redirect()->back()->with('error', 'Erro ao cadastrar a máquina. O ID de hardware já existe.')->withInput();
            }

            // Caso outro erro ocorra
            return redirect()->back()->with('error', 'Ocorreu um erro inesperado ao cadastrar a máquina.')->withInput();
        }
    }

    // Função para deletar uma máquina específica
    public function destroy(Machine $machine, TunnelManager $tunnels)
    {
        // Fecha os túneis ativos e pendentes antes: a exclusão apaga as solicitações
        // em cascata, mas não remove a regra do firewall nem encerra o túnel.
        try {
            foreach ($machine->connectionRequests()->active()->get() as $connectionRequest) {
                $tunnels->close($connectionRequest);
            }
        } catch (ProcessFailedException $e) {
            Log::error("Erro ao fechar os túneis da máquina {$machine->id} antes de excluí-la: " . $e->getMessage());
            return redirect()->route('dashboard')->with('error', 'Não foi possível fechar o túnel da máquina, então ela não foi excluída. Tente novamente.');
        }

        // Exclui a máquina
        $machine->delete();

        // Redireciona de volta ao dashboard com uma mensagem de sucesso
        return redirect()->route('dashboard')->with('success', 'Máquina excluída com sucesso!');
    }
}
