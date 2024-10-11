import os
import platform
import subprocess
import requests
import sys
import time
import threading

# Configurações
CHECK_TUNNEL_ENDPOINT = "http://195.200.5.90/api/check-tunnel-request"
USERNAME_SSH = "sshuser"
SERVER_ADDRESS = "195.200.5.90"
PRIVATE_KEY_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "chave_ssh")
HARDWARE_ID = "210382059501216"  # Pode ser gerado dinamicamente

def check_tunnel_request():
    data = {
        "hardware_id": HARDWARE_ID
    }
    try:
        response = requests.post(CHECK_TUNNEL_ENDPOINT, json=data, timeout=10)
        print("Resposta do servidor web:", response.text)
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
    print(f"Iniciando tentativa de conexão SSH na porta {server_port} para o serviço {service_port}...")
    ssh_command = [
        "ssh",
        "-i", PRIVATE_KEY_PATH,
        "-o", "BatchMode=yes",
        "-R", f"{server_port}:localhost:{service_port}",
        f"{USERNAME_SSH}@{SERVER_ADDRESS}",
        "-N"
    ]
    
    try:
        result = subprocess.run(ssh_command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        print("Saída do comando SSH:", result.stdout)
        print("Erros do comando SSH:", result.stderr)

        if result.returncode == 0:
            print(f"Túnel SSH estabelecido: localhost:{server_port} -> {SERVER_ADDRESS}:{service_port}")
        else:
            print(f"Falha ao estabelecer o túnel SSH. Código de retorno: {result.returncode}")
    except Exception as e:
        print(f"Erro ao tentar estabelecer o túnel SSH: {e}")

def verificar_e_abrir_tunel():
    tunnel_info = check_tunnel_request()
    if tunnel_info:
        server_port = tunnel_info.get("server_port")
        service_port = tunnel_info.get("service_port")
        
        if server_port and service_port:
            # Abrir o túnel em uma nova thread
            threading.Thread(target=establish_ssh_tunnel, args=(server_port, service_port)).start()
        else:
            print("Erro: As portas 'server_port' e 'service_port' não foram fornecidas.")

def main():
    while True:
        verificar_e_abrir_tunel()
        time.sleep(5)

if __name__ == "__main__":
    main()

