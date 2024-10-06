<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Laravel</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,600&display=swap" rel="stylesheet" />

        <!-- Styles -->
        <style>
            /* Tailwind CSS */
        </style>
    </head>
    <body class="font-sans antialiased dark:bg-black dark:text-white/50">
        <div class="bg-gray-50 text-black/50 dark:bg-black dark:text-white/50 min-h-screen flex flex-col justify-center items-center">
            <div class="bg-white rounded-lg shadow-xl p-8 max-w-lg w-full">
                <h2 class="text-center text-2xl font-bold mb-4">Cadastrar Cliente ou Máquina</h2>

                <!-- Formulário para cadastrar Cliente -->
                <form id="addClientForm" action="{{ route('clients.store') }}" method="POST">
                    @csrf
                    <h5 class="text-lg font-semibold mb-2">Cadastrar Cliente</h5>
                    <div class="mb-3">
                        <label for="clientName" class="form-label">Nome do Cliente</label>
                        <input type="text" class="form-control w-full border rounded-lg p-2" id="clientName" name="name" required>
                    </div>
                    <button type="submit" class="btn btn-success w-full bg-green-500 text-white py-2 px-4 rounded-lg">Cadastrar Cliente</button>
                </form>

                <hr class="my-6">

                <!-- Formulário para cadastrar Máquina -->
                <form id="addMachineForm" action="{{ route('machines.store') }}" method="POST">
                    @csrf
                    <h5 class="text-lg font-semibold mb-2">Cadastrar Máquina</h5>
                    <div class="mb-3">
                        <label for="clientSelect" class="form-label">Cliente</label>
                        <select class="form-control w-full border rounded-lg p-2" id="clientSelect" name="client_id" required>
                            <option value="">Selecione o Cliente</option>
                            @foreach ($clients as $client)
                                <option value="{{ $client->id }}">{{ $client->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="machineName" class="form-label">Nome da Máquina</label>
                        <input type="text" class="form-control w-full border rounded-lg p-2" id="machineName" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="specifications" class="form-label">Especificações</label>
                        <input type="text" class="form-control w-full border rounded-lg p-2" id="specifications" name="specifications">
                    </div>
                    <div class="mb-3">
                        <label for="hardwareId" class="form-label">Hardware ID</label>
                        <input type="text" class="form-control w-full border rounded-lg p-2" id="hardwareId" name="hardware_id" required>
                    </div>
                    <button type="submit" class="btn btn-success w-full bg-green-500 text-white py-2 px-4 rounded-lg">Cadastrar Máquina</button>
                </form>
            </div>
        </div>
    </body>
</html>

