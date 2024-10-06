<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class MachineController extends Controller
{
    // Função para armazenar uma nova máquina
    public function store(Request $request)
    {
        // Valida os dados recebidos
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
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
            // Captura o erro e redireciona com uma mensagem de erro
            return redirect()->route('dashboard')->with('error', 'Erro ao cadastrar a máquina. O ID de hardware já existe.');
        }
    }

    // Função para deletar uma máquina específica
    public function destroy(Machine $machine)
    {
        // Exclui a máquina
        $machine->delete();

        // Redireciona de volta ao dashboard com uma mensagem de sucesso
        return redirect()->route('dashboard')->with('success', 'Máquina excluída com sucesso!');
    }
}
