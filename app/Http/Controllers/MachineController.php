<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use Illuminate\Http\Request;

class MachineController extends Controller
{
    public function store(Request $request)
    {
        // Valida os dados recebidos
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'name' => 'required|string|max:255',
            'specifications' => 'nullable|string',
            'hardware_id' => 'required|string|max:255',
        ]);

        // Cria a nova máquina
        Machine::create([
            'client_id' => $validated['client_id'],
            'name' => $validated['name'],
            'specifications' => $validated['specifications'],
            'hardware_id' => $validated['hardware_id'],
        ]);

        // Redireciona de volta ao dashboard com uma mensagem de sucesso
        return redirect()->route('dashboard')->with('success', 'Máquina cadastrada com sucesso!');
    }
}

