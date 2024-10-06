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
                                        <th class="px-4 py-2 text-gray-800">Serviço do Cliente</th>
                                        <th class="px-4 py-2 text-gray-800">Porta Para Conexão</th>
                                        <th class="px-4 py-2 text-gray-800">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($client->machines as $machine)
                                        <tr>
                                            <td class="border px-4 py-2 text-gray-800">{{ $machine->name }}</td>
                                            <td class="border px-4 py-2 text-gray-800">{{ $machine->specifications }}</td>
                                            <td class="border px-4 py-2 text-gray-800">
                                                <input type="number" name="service" class="form-control w-full border rounded-lg p-2" placeholder="Porta do cliente que deseja utilizar">
                                            </td>
                                            <td class="border px-4 py-2 text-gray-800">
                                                <input type="number" name="random_port" class="form-control w-full border rounded-lg p-2" value="" placeholder="Porta para conectar no cliente" disabled>
                                            </td>
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

                <!-- Botões para cadastrar cliente, máquina e excluir -->
                <div class="flex justify-between mt-6">
                    <button class="bg-green-500 text-black px-4 py-2 rounded hover:bg-green-700" onclick="showClientModal()">
                        Cadastrar Cliente
                    </button>
                    <button class="bg-green-500 text-black px-4 py-2 rounded hover:bg-green-700" onclick="showMachineModal()">
                        Cadastrar Máquina
                    </button>
                    <button class="bg-red-500 text-black px-4 py-2 rounded hover:bg-red-700" onclick="showDeleteModal()">
                        Excluir Cliente/Máquina
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

    <!-- Modal para excluir cliente ou máquina -->
    <div id="deleteModal" class="fixed inset-0 hidden items-center justify-center z-50">
        <div class="bg-white p-6 rounded-lg shadow-lg">
            <h2 class="text-xl font-semibold mb-4 text-gray-800">Excluir Cliente ou Máquina</h2>
            <div class="mb-4">
                <button class="bg-blue-500 text-black px-4 py-2 rounded hover:bg-blue-700" onclick="showDeleteClientOptions()">
                    Excluir Cliente
                </button>
                <button class="bg-blue-500 text-black px-4 py-2 rounded hover:bg-blue-700 ml-4" onclick="showDeleteMachineOptions()">
                    Excluir Máquina
                </button>
            </div>
            <div id="deleteClientOptions" class="hidden">
                <label for="deleteClientSelect" class="block text-sm font-medium text-gray-800">Selecione o Cliente</label>
                <select id="deleteClientSelect" class="form-control w-full border rounded-lg p-2">
                    <option value="">Selecione um Cliente</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }}</option>
                    @endforeach
                </select>
                <button class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-700 mt-4" onclick="deleteClient()">
                    Excluir Cliente
                </button>
            </div>
            <div id="deleteMachineOptions" class="hidden">
                <label for="deleteClientSelectMachine" class="block text-sm font-medium text-gray-800">Selecione o Cliente</label>
                <select id="deleteClientSelectMachine" class="form-control w-full border rounded-lg p-2" onchange="loadMachines(this.value)">
                    <option value="">Selecione um Cliente</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }}</option>
                    @endforeach
                </select>
                <label for="deleteMachineSelect" class="block text-sm font-medium text-gray-800 mt-4">Selecione a Máquina</label>
                <select id="deleteMachineSelect" class="form-control w-full border rounded-lg p-2 mt-2" disabled>
                    <option value="">Selecione uma Máquina</option>
                </select>
                <button class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-700 mt-4" onclick="deleteMachine()">
                    Excluir Máquina
                </button>
            </div>
            <div class="flex justify-end mt-6">
                <button type="button" onclick="hideDeleteModal()" class="bg-gray-400 text-black px-4 py-2 rounded mr-2">
                    Cancelar
                </button>
            </div>
        </div>
    </div>

    <script>
        function showClientModal() {
            document.getElementById('clientModal').style.display = 'flex';
            addEscListener(); // Adiciona o listener para fechar com ESC
        }
        function hideClientModal() {
            document.getElementById('clientModal').style.display = 'none';
            removeEscListener(); // Remove o listener quando o modal é fechado
        }

        function showMachineModal() {
            document.getElementById('machineModal').style.display = 'flex';
            addEscListener(); // Adiciona o listener para fechar com ESC
        }
        function hideMachineModal() {
            document.getElementById('machineModal').style.display = 'none';
            removeEscListener(); // Remove o listener quando o modal é fechado
        }

        function showDeleteModal() {
            document.getElementById('deleteModal').style.display = 'flex';
            addEscListener(); // Adiciona o listener para fechar com ESC
        }
        function hideDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
            removeEscListener(); // Remove o listener quando o modal é fechado
        }

        function toggleDropdown(clientId) {
            var dropdown = document.getElementById('dropdown-' + clientId);
            if (dropdown.classList.contains('hidden')) {
                dropdown.classList.remove('hidden');
            } else {
                dropdown.classList.add('hidden');
            }
        }

        function showDeleteClientOptions() {
            document.getElementById('deleteClientOptions').classList.remove('hidden');
            document.getElementById('deleteMachineOptions').classList.add('hidden');
        }

        function showDeleteMachineOptions() {
            document.getElementById('deleteMachineOptions').classList.remove('hidden');
            document.getElementById('deleteClientOptions').classList.add('hidden');
        }

        function loadMachines(clientId) {
            var machineSelect = document.getElementById('deleteMachineSelect');
            machineSelect.disabled = false;
            machineSelect.innerHTML = ''; // Limpa as máquinas anteriores

            @foreach ($clients as $client)
                if (clientId == {{ $client->id }}) {
                    @foreach ($client->machines as $machine)
                        var option = document.createElement('option');
                        option.value = "{{ $machine->id }}";
                        option.text = "{{ $machine->name }}";
                        machineSelect.appendChild(option);
                    @endforeach
                }
            @endforeach
        }

        function deleteClient() {
            var clientId = document.getElementById('deleteClientSelect').value;
            if (clientId) {
                var form = document.createElement('form');
                form.action = '/clients/' + clientId;
                form.method = 'POST';
                var csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_token';
                csrfInput.value = '{{ csrf_token() }}';
                form.appendChild(csrfInput);
                var methodInput = document.createElement('input');
                methodInput.type = 'hidden';
                methodInput.name = '_method';
                methodInput.value = 'DELETE';
                form.appendChild(methodInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function deleteMachine() {
            var machineId = document.getElementById('deleteMachineSelect').value;
            if (machineId) {
                var form = document.createElement('form');
                form.action = '/machines/' + machineId;
                form.method = 'POST';
                var csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_token';
                csrfInput.value = '{{ csrf_token() }}';
                form.appendChild(csrfInput);
                var methodInput = document.createElement('input');
                methodInput.type = 'hidden';
                methodInput.name = '_method';
                methodInput.value = 'DELETE';
                form.appendChild(methodInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Funções para adicionar e remover o listener do ESC
        function addEscListener() {
            document.addEventListener('keydown', escFunction);
        }

        function removeEscListener() {
            document.removeEventListener('keydown', escFunction);
        }

        // Função que será chamada quando a tecla ESC for pressionada
        function escFunction(event) {
            if (event.key === 'Escape') {
                hideClientModal();
                hideMachineModal();
                hideDeleteModal();
            }
        }
    </script>
</x-app-layout>

