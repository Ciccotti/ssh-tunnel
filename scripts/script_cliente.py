import os
import platform
import subprocess
import requests
import sys
import time
import threading
import uuid
import getpass
import ctypes

CHECK_TUNNEL_ENDPOINT = "https://ip-do-servidor/api/check-tunnel-request"
USERNAME_SSH = "user"
SERVER_ADDRESS = "ip-do-servidor"
PRIVATE_KEY_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "nome-da-chave-ssh")
# Caminho do known_hosts usado para validar o host SSH do servidor.
# Preencha previamente com a chave pública do servidor (ssh-keyscan) para
# mitigar ataques man-in-the-middle. Se não existir, 'accept-new' será usado
# apenas na primeira conexão (TOFU – Trust On First Use).
KNOWN_HOSTS_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "known_hosts")
HARDWARE_ID = None


def _run_silent(args):
    """Executa um comando (lista de args) sem shell e sem expor stdout/stderr.

    Evita uso de shell=True com f-strings, que é vetor clássico de injeção
    quando qualquer argumento vem de fonte não confiável (ex.: nome de usuário).
    """
    return subprocess.run(
        args,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        shell=False,
        check=False,
    )


def set_private_key_permissions():
    system = platform.system()
    if os.path.exists(PRIVATE_KEY_PATH):
        if system in ['Linux', 'Darwin']:
            try:
                os.chmod(PRIVATE_KEY_PATH, 0o600)
                print(f"Permissões do arquivo de chave privada ajustadas para 0o600 no {system}.")
            except Exception as e:
                print(f"Erro ao ajustar permissões da chave privada no {system}: {e}")
        elif system == 'Windows':
            try:
                username = getpass.getuser()

                # icacls: remover herança
                _run_silent(['icacls', PRIVATE_KEY_PATH, '/inheritance:r'])

                # remover grupos padrão
                groups_to_remove = ["Everyone", "Users", "Authenticated Users", "BUILTIN\\Users"]
                for group in groups_to_remove:
                    _run_silent(['icacls', PRIVATE_KEY_PATH, '/remove', group])

                # conceder apenas leitura ao usuário atual
                _run_silent(['icacls', PRIVATE_KEY_PATH, '/grant:r', f'{username}:(R)'])

                # tomar posse
                _run_silent(['takeown', '/f', PRIVATE_KEY_PATH])

                print(f"Permissões do arquivo de chave privada ajustadas no {system}.")
            except Exception as e:
                print(f"Erro ao ajustar permissões da chave privada no {system}: {e}")
    else:
        print(f"O arquivo de chave privada '{PRIVATE_KEY_PATH}' não foi encontrado.")
        sys.exit(1)

def is_admin():
    try:
        return ctypes.windll.shell32.IsUserAnAdmin()
    except:
        return False

def get_hardware_id():
    system = platform.system()
    if system == "Windows":
        try:
            import wmi
            c = wmi.WMI()
            hardware_ids = []

            for cpu in c.Win32_Processor():
                processor_id = cpu.ProcessorId
                if processor_id and processor_id.strip() not in ["", "To be filled by O.E.M.", "Default string"]:
                    hardware_ids.append(processor_id.strip())
                break

            for board in c.Win32_BaseBoard():
                serial_number = board.SerialNumber
                if serial_number and serial_number.strip() not in ["", "To be filled by O.E.M.", "Default string"]:
                    hardware_ids.append(serial_number.strip())
                break

            for bios in c.Win32_BIOS():
                bios_serial = bios.SerialNumber
                if bios_serial and bios_serial.strip() not in ["", "To be filled by O.E.M.", "Default string"]:
                    hardware_ids.append(bios_serial.strip())
                break

            if hardware_ids:
                return '-'.join(hardware_ids)
            else:
                print("Não foi possível obter nenhum identificador de hardware válido.")
                sys.exit(1)
        except ImportError:
            print("A biblioteca 'WMI' é necessária no Windows. Instale-a com 'pip install wmi'.")
            sys.exit(1)
    else:
        return str(uuid.getnode())

def check_tunnel_request():
    data = {
        "hardware_id": HARDWARE_ID
    }
    try:
        # verify=True é o default, mas deixamos explícito: garante que o
        # certificado TLS do servidor seja validado (evita MITM via TLS).
        response = requests.post(CHECK_TUNNEL_ENDPOINT, json=data, timeout=10, verify=True)
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


def _ssh_host_key_options():
    """Retorna opções de verificação de chave de host do SSH.

    Se existir um arquivo known_hosts ao lado do script, usa verificação
    estrita contra ele. Caso contrário, cai para 'accept-new' que confia
    no host apenas na primeira conexão (TOFU) e rejeita se a chave mudar.

    'StrictHostKeyChecking=no' foi removido por permitir MITM silencioso.
    """
    if os.path.exists(KNOWN_HOSTS_PATH):
        return [
            '-o', 'StrictHostKeyChecking=yes',
            '-o', f'UserKnownHostsFile={KNOWN_HOSTS_PATH}',
        ]
    return [
        '-o', 'StrictHostKeyChecking=accept-new',
        '-o', f'UserKnownHostsFile={KNOWN_HOSTS_PATH}',
    ]


def is_valid_ssh_key():
    ssh_command = [
        "ssh",
        "-i", PRIVATE_KEY_PATH,
        "-o", "BatchMode=yes",
        *_ssh_host_key_options(),
        f"{USERNAME_SSH}@{SERVER_ADDRESS}",
        "exit"
    ]
    try:
        result = subprocess.run(ssh_command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)
        if result.returncode == 0:
            return True
        else:
            print("Chave SSH inválida ou rejeitada pelo servidor. Código de retorno:", result.returncode)
            return False
    except Exception as e:
        print(f"Erro ao validar a chave SSH: {e}")
        return False


def establish_ssh_tunnel(server_port, service_port):
    # Valida as portas antes de passá-las ao comando SSH, já que vêm de
    # uma resposta HTTP remota.
    try:
        server_port = int(server_port)
        service_port = int(service_port)
    except (TypeError, ValueError):
        print("Portas inválidas recebidas do servidor.")
        return

    if not (1 <= server_port <= 65535 and 1 <= service_port <= 65535):
        print(f"Portas fora do intervalo válido: server={server_port}, service={service_port}")
        return

    print(f"Iniciando tentativa de conexão SSH na porta {server_port} para o serviço {service_port}...")
    ssh_command = [
        "ssh",
        "-i", PRIVATE_KEY_PATH,
        "-o", "BatchMode=yes",
        *_ssh_host_key_options(),
        "-R", f"{server_port}:localhost:{service_port}",
        f"{USERNAME_SSH}@{SERVER_ADDRESS}",
        "-N"
    ]

    try:
        result = subprocess.run(ssh_command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)
        print(result.stdout)
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
            threading.Thread(target=establish_ssh_tunnel, args=(server_port, service_port)).start()
        else:
            print("Erro: As portas 'server_port' e 'service_port' não foram fornecidas.")

def main():
    global HARDWARE_ID
    HARDWARE_ID = get_hardware_id()
    print(f"Hardware ID: {HARDWARE_ID}")

    if platform.system() == 'Windows' and not is_admin():
        print("Este script precisa ser executado como administrador no Windows.")
        sys.exit(1)

    set_private_key_permissions()

    if not is_valid_ssh_key():
        print("A chave SSH é inválida. Encerrando o script.")
        sys.exit(1)

    while True:
        verificar_e_abrir_tunel()
        time.sleep(5)

if __name__ == "__main__":
    main()
