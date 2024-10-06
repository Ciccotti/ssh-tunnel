<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClientController extends Controller
{
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
}


