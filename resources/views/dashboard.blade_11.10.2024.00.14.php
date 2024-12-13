<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <!-- Exibe mensagens de sucesso ou erro -->
    @if (session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline">{{ session('success') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline">{{ session('error') }}</span>
        </div>
    @endif

    <!-- Exibe erros de validação -->
    @if ($errors->any())
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

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
                                        <th class="px-4 py-2 text-gray-800">Porta do Cliente</th>
                                        <th class="px-4 py-2 text-gray-800">Endereço de Conexão</th>
                                        <th class="px-4 py-2 text-gray-800">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($client->machines as $machine)
                                        @php
                                            $connectionRequest = $machine->connectionRequests()->latest()->first();
                                            $isPending = $connectionRequest && $connectionRequest->status === 'pending';
                                            $isInProgress = $connectionRequest && $connectionRequest->status === 'in_progress';
                                        @endphp
                                        <tr>
                                            <td class="border px-4 py-2 text-gray-800">{{ $machine->name }}</td>
                                            <td class="border px-4 py-2 text-gray-800">{{ $machine->specifications }}</td>
                                            <td class="border px-4 py-2 text-gray-800">
                                                <input type="number" name="service_port" class="service-port form-control w-full border rounded-lg p-2" placeholder="Ex: 3389" data-machine-id="{{ $machine->id }}">
                                            </td>
                                            <td class="border px-4 py-2 text-gray-800">
                                                <input type="text" class="random-port form-control w-full border rounded-lg p-2" value="{{ $isInProgress ? '195.200.5.90:' . $machine->random_port : '' }}" placeholder="Clique em 'Abrir Túnel' e aguarde" data-machine-id="{{ $machine->id }}" readonly>
                                            </td>

                                            <td class="border px-4 py-2">
                                                @if ($isPending)
                                                    <button class="bg-gray-400 text-black px-4 py-2 rounded" disabled>
                                                        Conectando...
                                                    </button>
                                                @elseif ($isInProgress)
                                                    <button class="toggle-tunnel-btn bg-red-500 text-black px-4 py-2 rounded hover:bg-red-700" 
                                                            data-machine-id="{{ $machine->id }}" 
                                                            data-open="false">
                                                        Fechar Túnel
                                                    </button>
                                                @else
                                                    <button class="toggle-tunnel-btn bg-blue-500 text-black px-4 py-2 rounded hover:bg-blue-700" 
                                                            data-machine-id="{{ $machine->id }}" 
                                                            data-open="true">
                                                        Abrir Túnel
                                                    </button>
                                                @endif
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
    <div id="clientModal" class="fixed inset-0 hidden items-center justify-center z-50 bg-black bg-opacity-50">
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
                    <button type="submit" class="bg-green-500 text-black px-4 py-2 rounded hover:bg-green-700">
                        Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para cadastrar máquina -->
    <div id="machineModal" class="fixed inset-0 hidden items-center justify-center z-50 bg-black bg-opacity-50">
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
                    <button type="submit" class="bg-green-500 text-black px-4 py-2 rounded hover:bg-green-700">
                        Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para excluir cliente ou máquina -->
    <div id="deleteModal" class="fixed inset-0 hidden items-center justify-center z-50 bg-black bg-opacity-50">
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
                <button class="bg-red-500 text-black px-4 py-2 rounded hover:bg-red-700 mt-4" onclick="deleteClient()">
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
                <button class="bg-red-500 text-black px-4 py-2 rounded hover:bg-red-700 mt-4" onclick="deleteMachine()">
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
            addEscListener();
        }

        function hideClientModal() {
            document.getElementById('clientModal').style.display = 'none';
            removeEscListener();
        }

        function showMachineModal() {
            document.getElementById('machineModal').style.display = 'flex';
            addEscListener();
        }

        function hideMachineModal() {
            document.getElementById('machineModal').style.display = 'none';
            removeEscListener();
        }

        function showDeleteModal() {
            document.getElementById('deleteModal').style.display = 'flex';
            addEscListener();
        }

        function hideDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
            removeEscListener();
        }

        function showDeleteClientOptions() {
            document.getElementById('deleteClientOptions').classList.remove('hidden');
            document.getElementById('deleteMachineOptions').classList.add('hidden');
        }

        function showDeleteMachineOptions() {
            document.getElementById('deleteMachineOptions').classList.remove('hidden');
            document.getElementById('deleteClientOptions').classList.add('hidden');
        }

        function toggleDropdown(clientId) {
            var dropdown = document.getElementById('dropdown-' + clientId);
            dropdown.classList.toggle('hidden');
        }

        function loadMachines(clientId) {
            var machineSelect = document.getElementById('deleteMachineSelect');
            machineSelect.disabled = false;
            machineSelect.innerHTML = '<option value="">Selecione uma Máquina</option>';

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

        // Atualiza os listeners para o campo de Endereço de Conexão
        document.querySelectorAll('.random-port').forEach(input => {
            input.addEventListener('mouseover', function() {
                this.placeholder = 'Clique aqui para copiar';
            });
            input.addEventListener('mouseout', function() {
                this.placeholder = "Clique em 'Abrir Túnel' e aguarde";
            });

            // Adiciona o evento de clique para copiar o endereço
            input.addEventListener('click', function() {
                // Copia o valor para o clipboard
                navigator.clipboard.writeText(this.value).then(function() {
                    // Feedback opcional
                    alert('Endereço copiado para a área de transferência!');
                }, function(err) {
                    console.error('Erro ao copiar para o clipboard: ', err);
                });
            });
        });

        document.querySelectorAll('.toggle-tunnel-btn').forEach(button => {
            button.addEventListener('click', function () {
                const machineId = this.getAttribute('data-machine-id');
                const isOpening = this.getAttribute('data-open') === 'true';
                let servicePort = '';

                // Se for abrir o túnel, validamos a porta do cliente
                if (isOpening) {
                    const servicePortInput = document.querySelector(`input[name="service_port"][data-machine-id="${machineId}"]`);
                    servicePort = servicePortInput.value;

                    // Verifica se o campo está vazio
                    if (servicePort === '') {
                        // Usa a porta padrão 3389
                        servicePort = '3389';
                        // Mostra ela no campo
                        servicePortInput.value = servicePort;
                    }
                }

                const url = isOpening ? `/machines/${machineId}/open-tunnel` : `/machines/${machineId}/close-tunnel`;

                // Exibe "Conectando..." ao abrir o túnel
                if (isOpening) {
                    this.innerText = "Conectando...";
                    this.disabled = true;
                }

                fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ service_port: servicePort })
                })
                .then(response => response.json())
                .then(data => {
                    console.log('Resposta do servidor:', data); // Para depuração

                    if (data.success) {
                        const randomPortInput = document.querySelector(`input.random-port[data-machine-id="${machineId}"]`);
                        const servicePortInput = document.querySelector(`input[name="service_port"][data-machine-id="${machineId}"]`);

                        if (randomPortInput && data.server_port) {
                            randomPortInput.value = `195.200.5.90:${data.server_port}`;  // Atualiza o campo com o endereço completo
                        }

                        // Verifica o status retornado para definir o estado do botão
                        if (isOpening && data.status === 'in_progress') {
                            this.innerText = "Fechar Túnel";
                            this.setAttribute('data-open', 'false');
                            this.disabled = false;
                            this.classList.remove('bg-blue-500');
                            this.classList.add('bg-red-500');
                        } else if (!isOpening) {
                            this.innerText = "Abrir Túnel";
                            this.setAttribute('data-open', 'true');
                            this.classList.remove('bg-red-500');
                            this.classList.add('bg-blue-500');

                            // Limpa os campos
                            if (randomPortInput) {
                                randomPortInput.value = '';
                            }
                            if (servicePortInput) {
                                servicePortInput.value = '';
                            }
                        }
                    } else {
                        alert('Erro ao executar a ação: ' + data.message);
                        this.innerText = isOpening ? "Abrir Túnel" : "Fechar Túnel";
                        this.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    alert('Ocorreu um erro ao tentar abrir o túnel. Verifique o console para mais detalhes.');
                    this.innerText = isOpening ? "Abrir Túnel" : "Fechar Túnel";
                    this.disabled = false;
                });
            });
        });

        // Função para verificar o status do túnel periodicamente e atualizar a interface
        function checkTunnelStatus(machineId, button) {
            fetch(`/machines/${machineId}/tunnel-status`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            })
            .then(response => response.json())
            .then(data => {
                const randomPortInput = document.querySelector(`input.random-port[data-machine-id="${machineId}"]`);
                const servicePortInput = document.querySelector(`input[name="service_port"][data-machine-id="${machineId}"]`);
                if (data.status === 'in_progress') {
                    button.innerText = "Fechar Túnel";
                    button.setAttribute('data-open', 'false');
                    button.disabled = false;
                    button.classList.remove('bg-blue-500');
                    button.classList.add('bg-red-500');

                    if (randomPortInput && data.server_port) {
                        randomPortInput.value = `195.200.5.90:${data.server_port}`;
                    }
                } else if (data.status === 'pending') {
                    button.innerText = "Conectando...";
                    button.disabled = true;
                } else {
                    button.innerText = "Abrir Túnel";
                    button.setAttribute('data-open', 'true');
                    button.classList.remove('bg-red-500');
                    button.classList.add('bg-blue-500');
                    button.disabled = false;

                    // Limpa os campos
                    if (randomPortInput) {
                        randomPortInput.value = '';
                    }
                    if (servicePortInput) {
                        servicePortInput.value = '';
                    }
                }
            })
            .catch(error => {
                console.error('Erro ao verificar o status do túnel:', error);
            });
        }

        // Inicializa a verificação do status para cada máquina
        document.querySelectorAll('.toggle-tunnel-btn').forEach(button => {
            const machineId = button.getAttribute('data-machine-id');
            // Verifica o status a cada 5 segundos
            setInterval(() => checkTunnelStatus(machineId, button), 5000);
        });

        function addEscListener() {
            document.addEventListener('keydown', escFunction);
        }

        function removeEscListener() {
            document.removeEventListener('keydown', escFunction);
        }

        function escFunction(event) {
            if (event.key === 'Escape') {
                hideClientModal();
                hideMachineModal();
                hideDeleteModal();
            }
        }
    </script>
</x-app-layout>
