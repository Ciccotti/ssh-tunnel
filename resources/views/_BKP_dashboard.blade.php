<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                <!-- Aqui vai o conteúdo principal do dashboard -->
                <h1 class="text-2xl font-bold mb-6">Bem-vindo ao Dashboard</h1>

                <!-- Exibindo a tabela de clientes e máquinas -->
                @if(isset($clients) && $clients->isNotEmpty())
                    <p class="mb-4">Abaixo está a lista de clientes e máquinas cadastradas:</p>

                    <div class="table-responsive">
                        <table class="table-auto w-full bg-white shadow-md rounded-lg">
                            <thead>
                                <tr class="bg-gray-200 text-gray-600 uppercase text-sm leading-normal">
                                    <th class="py-3 px-6 text-left">Cliente</th>
                                    <th class="py-3 px-6 text-left">Máquina</th>
                                    <th class="py-3 px-6 text-left">Especificações</th>
                                    <th class="py-3 px-6 text-left">Hardware ID</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-600 text-sm font-light">
                                @foreach ($clients as $client)
                                    <tr class="border-b border-gray-200 hover:bg-gray-100">
                                        <td class="py-3 px-6 text-left font-medium" rowspan="{{ $client->machines->count() + 1 }}">{{ $client->name }}</td>
                                    </tr>
                                    @foreach ($client->machines as $machine)
                                        <tr class="border-b border-gray-200 hover:bg-gray-100">
                                            <td class="py-3 px-6">{{ $machine->name }}</td>
                                            <td class="py-3 px-6">{{ $machine->specifications }}</td>
                                            <td class="py-3 px-6">{{ $machine->hardware_id }}</td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p>Nenhum cliente cadastrado.</p>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>

