<div class="p-6 lg:p-8 bg-white border-b border-gray-200">
    <x-application-logo class="block h-12 w-auto" />

    <h1 class="mt-8 text-2xl font-medium text-gray-900">
        Dashboard - Clientes e Máquinas
    </h1>

    <p class="mt-6 text-gray-500 leading-relaxed">
        Gerencie seus clientes e suas respectivas máquinas.
    </p>
</div>

<div class="bg-gray-200 bg-opacity-25 grid grid-cols-1 md:grid-cols-2 gap-6 lg:gap-8 p-6 lg:p-8">
    <div>
        <h2 class="text-xl font-semibold text-gray-900">
            Cadastrar Cliente
        </h2>
        <form action="{{ route('clients.store') }}" method="POST">
            @csrf
            <div class="mb-4">
                <label for="clientName" class="block text-sm font-medium text-gray-700">Nome do Cliente</label>
                <input type="text" name="name" id="clientName" class="form-control w-full border rounded-lg p-2" required>
            </div>
            <button type="submit" class="btn btn-success">Cadastrar Cliente</button>
        </form>
    </div>

    <div>
        <h2 class="text-xl font-semibold text-gray-900">
            Cadastrar Máquina
        </h2>
        <form action="{{ route('machines.store') }}" method="POST">
            @csrf
            <div class="mb-4">
                <label for="clientSelect" class="block text-sm font-medium text-gray-700">Cliente</label>
                <select name="client_id" id="clientSelect" class="form-control w-full border rounded-lg p-2" required>
                    <option value="">Selecione um Cliente</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-4">
                <label for="machineName" class="block text-sm font-medium text-gray-700">Nome da Máquina</label>
                <input type="text" name="name" id="machineName" class="form-control w-full border rounded-lg p-2" required>
            </div>
            <div class="mb-4">
                <label for="specifications" class="block text-sm font-medium text-gray-700">Especificações</label>
                <input type="text" name="specifications" id="specifications" class="form-control w-full border rounded-lg p-2">
            </div>
            <button type="submit" class="btn btn-success">Cadastrar Máquina</button>
        </form>
    </div>
</div>

<!-- Tabela de clientes e máquinas -->
<div class="bg-white p-6 rounded-lg shadow mt-8">
    <h2 class="text-xl font-semibold text-gray-900 mb-4">Clientes e Máquinas</h2>
    <table class="table table-striped w-full">
        <thead>
            <tr>
                <th>Cliente</th>
                <th>Máquina</th>
                <th>Especificações</th>
                <th>Hardware ID</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($clients as $client)
                <tr>
                    <td rowspan="{{ $client->machines->count() + 1 }}">{{ $client->name }}</td>
                </tr>
                @foreach ($client->machines as $machine)
                    <tr>
                        <td>{{ $machine->name }}</td>
                        <td>{{ $machine->specifications }}</td>
                        <td>{{ $machine->hardware_id }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</div>

