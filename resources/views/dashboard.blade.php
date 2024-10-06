<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                <h3 class="text-2xl font-semibold mb-6 text-gray-800">
                    Lista de Clientes e Máquinas
                </h3>

                @foreach ($clients as $client)
                    <div class="mb-4">
                        <!-- Botão para mostrar/ocultar as máquinas -->
                        <button class="bg-gray-200 w-full text-left py-2 px-4 rounded-md hover:bg-gray-300 text-gray-800" onclick="toggleDropdown({{ $client->id }})">
                            {{ $client->name }}
                        </button>
                        <!-- Div que contém as máquinas do cliente -->
                        <div id="dropdown-{{ $client->id }}" class="hidden bg-gray-100 p-4">
                            <table class="table-auto w-full mb-4">
                                <thead>
                                    <tr>
                                        <th class="px-4 py-2 text-gray-800">Máquina</th>
                                        <th class="px-4 py-2 text-gray-800">Especificações</th>
                                        <th class="px-4 py-2 text-gray-800">Serviço</th>
                                        <th class="px-4 py-2 text-gray-800">Porta Aleatória</th>
                                        <th class="px-4 py-2 text-gray-800">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($client->machines as $machine)
                                        <tr>
                                            <td class="border px-4 py-2 text-gray-800">{{ $machine->name }}</td>
                                            <td class="border px-4 py-2 text-gray-800">{{ $machine->specifications }}</td>
                                            <td class="border px-4 py-2 text-gray-800"></td>
                                            <td class="border px-4 py-2 text-gray-800"></td>
                                            <td class="border px-4 py-2">
                                                <button class="bg-blue-500 text-black px-4 py-2 rounded hover:bg-blue-700">
                                                    Abrir Túnel
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach

                <!-- Botões para cadastrar cliente e máquina -->
                <div class="flex justify-between mt-6">
                    <button class="bg-green-500 text-black px-4 py-2 rounded hover:bg-green-700" onclick="showClientModal()">
                        Cadastrar Cliente
                    </button>
                    <button class="bg-green-500 text-black px-4 py-2 rounded hover:bg-green-700" onclick="showMachineModal()">
                        Cadastrar Máquina
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para cadastrar cliente -->
    <div id="clientModal" class="fixed inset-0 hidden items-center justify-center z-50">
        <div class="bg-white p-6 rounded-lg shadow-lg">
            <h2 class="text-xl font-semibold mb-4 text-gray-800">Cadastrar Cliente</h2>
            <form action="{{ route('clients.store') }}" method="POST">
                @csrf
                <div class="mb-4">
                    <label for="clientName" class="block text-sm font-medium text-gray-800">Nome do Cliente</label>
                    <input type="text" name="name" id="clientName" class="form-control w-full border rounded-lg p-2" required>
                </div>
                <div class="flex justify-end">
                    <button type="button" onclick="hideClientModal()" class="bg-gray-400 text-black px-4 py-2 rounded mr-2">
                        Cancelar
                    </button>
                    <button type="submit" class="bg-green-500 text-black px-4 py-2 rounded">
                        Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para cadastrar máquina -->
    <div id="machineModal" class="fixed inset-0 hidden items-center justify-center z-50">
        <div class="bg-white p-6 rounded-lg shadow-lg">
            <h2 class="text-xl font-semibold mb-4 text-gray-800">Cadastrar Máquina</h2>
            <form action="{{ route('machines.store') }}" method="POST">
                @csrf
                <div class="mb-4">
                    <label for="clientSelect" class="block text-sm font-medium text-gray-800">Cliente</label>
                    <select name="client_id" id="clientSelect" class="form-control w-full border rounded-lg p-2" required>
                        <option value="">Selecione um Cliente</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-4">
                    <label for="machineName" class="block text-sm font-medium text-gray-800">Nome da Máquina</label>
                    <input type="text" name="name" id="machineName" class="form-control w-full border rounded-lg p-2" required>
                </div>
                <div class="mb-4">
                    <label for="specifications" class="block text-sm font-medium text-gray-800">Especificações</label>
                    <input type="text" name="specifications" id="specifications" class="form-control w-full border rounded-lg p-2">
                </div>
                <div class="mb-4">
                    <label for="hardwareId" class="block text-sm font-medium text-gray-800">Hardware ID</label>
                    <input type="text" name="hardware_id" id="hardwareId" class="form-control w-full border rounded-lg p-2" required>
                </div>
                <div class="flex justify-end">
                    <button type="button" onclick="hideMachineModal()" class="bg-gray-400 text-black px-4 py-2 rounded mr-2">
                        Cancelar
                    </button>
                    <button type="submit" class="bg-green-500 text-black px-4 py-2 rounded">
                        Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showClientModal() {
            document.getElementById('clientModal').style.display = 'flex';
        }
        function hideClientModal() {
            document.getElementById('clientModal').style.display = 'none';
        }

        function showMachineModal() {
            document.getElementById('machineModal').style.display = 'flex';
        }
        function hideMachineModal() {
            document.getElementById('machineModal').style.display = 'none';
        }

        function toggleDropdown(clientId) {
            var dropdown = document.getElementById('dropdown-' + clientId);
            if (dropdown.classList.contains('hidden')) {
                dropdown.classList.remove('hidden');
            } else {
                dropdown.classList.add('hidden');
            }
        }
    </script>
</x-app-layout>

