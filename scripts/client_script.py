import os
import platform
import subprocess
import uuid
import requests
import sys

# Configurações
CHECK_TUNNEL_ENDPOINT = "http://195.200.5.90/api/check-tunnel-request"  # URL para verificar solicitações de túnel
USERNAME_SSH = "sshuser"  # Usuário SSH para conexão
SERVER_ADDRESS = "195.200.5.90"  # Endereço do servidor SSH

# Caminho para a chave privada (ajustando para sistemas operacionais diferentes)
PRIVATE_KEY_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "chave_ssh")

def adjust_key_permissions():
    system = platform.system()
    if system == "Windows":
        # No Windows, ajustar permissões com icacls
        try:
            result = subprocess.run([
                'icacls', PRIVATE_KEY_PATH, '/inheritance:r',
                '/grant:r', f'{os.getlogin()}:(R)'
            ], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            
            print("Permissões ajustadas com sucesso no Windows.")
            print(result.stdout)  # Exibe a saída do comando
        except Exception as e:
            print(f"Erro ao ajustar permissões no Windows: {e}")
            sys.exit(1)
    elif system in ["Linux", "Darwin"]:  # Para Linux e macOS
        try:
            # Ajustar permissões para 600 no Linux/macOS
            os.chmod(PRIVATE_KEY_PATH, 0o600)
            print("Permissões ajustadas com sucesso no Linux/macOS.")
        except Exception as e:
            print(f"Erro ao ajustar permissões no Linux/macOS: {e}")
            sys.exit(1)
    else:
        print(f"Sistema operacional {system} não suportado para ajuste automático de permissões.")
        sys.exit(1)

def get_hardware_id():
    system = platform.system()
    if system == "Windows":
        try:
            import wmi
            c = wmi.WMI()
            motherboard = c.Win32_BaseBoard()[0]
            return motherboard.SerialNumber.strip()
        except ImportError:
            print("A biblioteca 'WMI' é necessária no Windows. Instale-a com 'pip install WMI'.")
            sys.exit(1)
    elif system == "Linux":
        return str(uuid.getnode())  # Obtém o MAC address
    else:
        return str(uuid.getnode())

def check_tunnel_request(hardware_id):
    data = {
        "hardware_id": hardware_id
    }
    try:
        response = requests.post(CHECK_TUNNEL_ENDPOINT, json=data, timeout=10)
        print("Resposta do servidor web:", response.text)  # Exibir resposta no terminal

        if response.status_code == 200:
            data = response.json()
            if data.get("success") and data.get("status") == "pending":
                return data
            else:
                print("Nenhuma conexão pendente no momento.")
                return None
        else:
            print(f"Erro ao verificar solicitações de túnel. Status Code: {response.status_code}")
            return None
    except requests.RequestException as e:
        print(f"Erro ao conectar com o servidor: {e}")
        return None

def establish_ssh_tunnel(server_port, service_port):
    print(f"Iniciando tentativa de conexão SSH...")
    print(f"Comando de SSH sendo executado: -i {PRIVATE_KEY_PATH} -R {server_port}:localhost:{service_port} {USERNAME_SSH}@{SERVER_ADDRESS}")
    
    # Construir o comando SSH para redirecionar a porta
    ssh_command = [
        "ssh",
        "-i", PRIVATE_KEY_PATH,
        "-o", "BatchMode=yes",  # Desabilita solicitação de senha
        "-R", f"{server_port}:localhost:{service_port}",
        f"{USERNAME_SSH}@{SERVER_ADDRESS}",
        "-N"
    ]
    
    # Executar o comando no terminal
    try:
        result = subprocess.run(ssh_command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        
        # Exibir saída e erro do comando SSH
        print("Saída do comando SSH:")
        print(result.stdout)
        print("Erros do comando SSH:")
        print(result.stderr)

        # Verificar se o túnel SSH foi estabelecido com sucesso
        if result.returncode == 0:
            print(f"Túnel SSH estabelecido: localhost:{server_port} -> {SERVER_ADDRESS}:{service_port}")
        else:
            print(f"Falha ao estabelecer o túnel SSH. Código de retorno: {result.returncode}")
    except Exception as e:
        print(f"Erro ao tentar estabelecer o túnel SSH: {e}")

def main():
    # Verificar se a chave existe antes de tentar qualquer operação
    if not os.path.isfile(PRIVATE_KEY_PATH):
        print(f"Chave privada não encontrada: {PRIVATE_KEY_PATH}")
        sys.exit(1)

    # Ajustar permissões da chave privada antes de tentar o SSH
    adjust_key_permissions()

    hardware_id = get_hardware_id()
    print(f"Hardware ID: {hardware_id}")
    
    # Verificar solicitações de túnel no servidor
    tunnel_info = check_tunnel_request(hardware_id)
    if not tunnel_info:
        print("Nenhuma conexão pendente ou resposta inválida do servidor.")
        sys.exit(0)
    
    # Verificar se as portas estão presentes na resposta
    server_port = tunnel_info.get("server_port")
    service_port = tunnel_info.get("service_port")
    
    if server_port and service_port:
        # Estabelecer túnel SSH
        establish_ssh_tunnel(server_port, service_port)
    else:
        print("Erro: As portas 'server_port' e 'service_port' não foram fornecidas.")
        sys.exit(1)

if __name__ == "__main__":
    main()
