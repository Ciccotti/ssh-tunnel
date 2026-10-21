"""Cliente do ssh-tunnel: consulta o painel e abre o túnel SSH reverso (ssh -R)
quando há uma solicitação pendente para esta máquina.

Duas formas de uso:

- Instalado pelo painel (recomendado): o comando de "Instalar em Nova Máquina"
  roda "script_cliente.py --config config.json --enroll <painel> <código>", que
  gera a chave SSH desta máquina, registra a máquina no painel e grava o
  config.json. Depois o serviço roda "script_cliente.py --config config.json".
- Manual: preencha as constantes abaixo, coloque a chave privada ao lado do
  script e cadastre no painel o Hardware ID mostrado na primeira execução.

Usa só a biblioteca padrão do Python. No Windows, o modo manual precisa do
pacote wmi para calcular o Hardware ID.
"""

import argparse
import ctypes
import getpass
import hashlib
import json
import os
import platform
import socket
import ssl
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
import uuid

# Configuração do modo manual. No modo instalado pelo painel, vem do config.json.
CHECK_TUNNEL_ENDPOINT = "https://ip-do-servidor/api/check-tunnel-request"
USERNAME_SSH = "user"
SERVER_ADDRESS = "ip-do-servidor"
SSH_PORT = 22
PRIVATE_KEY_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "nome-da-chave-ssh")
# Caminho do known_hosts usado para validar o host SSH do servidor.
# Preencha previamente com a chave pública do servidor (ssh-keyscan) para
# mitigar ataques man-in-the-middle. Se não existir, 'accept-new' será usado
# apenas na primeira conexão (TOFU – Trust On First Use).
KNOWN_HOSTS_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "known_hosts")
HARDWARE_ID = None

# Intervalo, em segundos, entre as consultas ao painel
POLL_INTERVAL = 5

# Portas do servidor com um "ssh -R" em andamento, para não abrir o mesmo túnel duas vezes
_active_ports = set()
_active_ports_lock = threading.Lock()

# Última mensagem de cada tipo, para não repetir a mesma linha a cada consulta
_last_messages = {}


def log_change(kind, message):
    """Mostra a mensagem só quando ela muda (evita uma linha a cada 5 segundos)."""
    if _last_messages.get(kind) != message:
        _last_messages[kind] = message
        print(message)


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


def _no_window():
    """No Windows, não abre janela de console para o ssh (o serviço roda em segundo plano)."""
    return subprocess.CREATE_NO_WINDOW if platform.system() == "Windows" else 0


def ssh_program(name="ssh"):
    """Caminho do ssh/ssh-keygen. No Windows, prefere o OpenSSH do sistema."""
    if platform.system() == "Windows":
        candidate = os.path.join(os.environ.get("WINDIR", r"C:\Windows"), "System32", "OpenSSH", name + ".exe")
        if os.path.exists(candidate):
            return candidate
    return name


def post_json(url, payload, timeout=15):
    """POST com corpo JSON. Devolve (status HTTP, resposta como dict).

    O certificado TLS do servidor é sempre validado. Erros de rede lançam OSError.
    """
    request = urllib.request.Request(
        url,
        data=json.dumps(payload).encode("utf-8"),
        method="POST",
        headers={"Content-Type": "application/json", "Accept": "application/json", "User-Agent": "ssh-tunnel-client"},
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout, context=ssl.create_default_context()) as response:
            status, body = response.status, response.read()
    except urllib.error.HTTPError as e:
        status, body = e.code, e.read()

    try:
        return status, json.loads(body.decode("utf-8") or "{}")
    except ValueError:
        return status, {"message": body.decode("utf-8", "replace")[:200]}


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
    """Hardware ID do modo manual (o mesmo das versões anteriores do cliente)."""
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


def stable_machine_id():
    """Hardware ID do modo instalado pelo painel.

    Usa o identificador da instalação do sistema (MachineGuid no Windows,
    /etc/machine-id no Linux), que não muda entre reinicializações nem depende
    de placa de rede. Vai para o painel só o hash, não o valor original.
    """
    raw = None
    if platform.system() == "Windows":
        import winreg
        with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r"SOFTWARE\Microsoft\Cryptography", 0,
                            winreg.KEY_READ | winreg.KEY_WOW64_64KEY) as key:
            raw = winreg.QueryValueEx(key, "MachineGuid")[0]
    else:
        for path in ("/etc/machine-id", "/var/lib/dbus/machine-id"):
            try:
                with open(path, encoding="ascii") as f:
                    raw = f.read().strip() or None
            except OSError:
                continue
            if raw:
                break

    if not raw:
        raw = str(uuid.getnode())

    return hashlib.sha256(f"ssh-tunnel:{raw.strip().lower()}".encode("utf-8")).hexdigest()


def check_tunnel_request():
    try:
        status, data = post_json(CHECK_TUNNEL_ENDPOINT, {"hardware_id": HARDWARE_ID}, timeout=10)
    except OSError as e:
        log_change("check", f"Erro ao conectar com o servidor: {e}")
        return None

    if status == 200:
        log_change("check", "Conectado ao painel. Aguardando solicitações de túnel.")
        if data.get("success") and data.get("status") == "pending":
            return data
        return None

    if status == 404:
        log_change("check", "Esta máquina não está cadastrada no painel (Hardware ID não encontrado).")
    else:
        log_change("check", f"Erro ao verificar solicitações de túnel. Status Code: {status}")
    return None


def _ssh_host_key_options():
    """Retorna opções de verificação de chave de host do SSH.

    Se existir um arquivo known_hosts ao lado do script, usa verificação
    estrita contra ele. Caso contrário, cai para 'accept-new' que confia
    no host apenas na primeira conexão (TOFU) e rejeita se a chave mudar.

    'StrictHostKeyChecking=no' foi removido por permitir MITM silencioso.
    """
    if os.path.exists(KNOWN_HOSTS_PATH) and os.path.getsize(KNOWN_HOSTS_PATH) > 0:
        return [
            '-o', 'StrictHostKeyChecking=yes',
            '-o', f'UserKnownHostsFile={KNOWN_HOSTS_PATH}',
        ]
    return [
        '-o', 'StrictHostKeyChecking=accept-new',
        '-o', f'UserKnownHostsFile={KNOWN_HOSTS_PATH}',
    ]


def _ssh_base_command():
    return [
        ssh_program(),
        "-i", PRIVATE_KEY_PATH,
        "-p", str(SSH_PORT),
        "-o", "BatchMode=yes",
        "-o", "IdentitiesOnly=yes",
        "-o", "ConnectTimeout=15",
        *_ssh_host_key_options(),
    ]


def check_private_key():
    """Confere se o ssh consegue usar a chave privada.

    O ssh recusa a chave se outras contas puderem lê-la (no Windows, inclusive
    pelas permissões NTFS do arquivo).
    """
    result = subprocess.run([ssh_program("ssh-keygen"), "-y", "-f", PRIVATE_KEY_PATH],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True,
                            creationflags=_no_window())
    if result.returncode != 0:
        print(f"Erro: o ssh não consegue usar a chave {PRIVATE_KEY_PATH}: {result.stderr.strip()}")
        return False
    return True


def is_valid_ssh_key():
    ssh_command = [*_ssh_base_command(), f"{USERNAME_SSH}@{SERVER_ADDRESS}", "exit"]
    try:
        result = subprocess.run(ssh_command, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                universal_newlines=True, timeout=60, creationflags=_no_window())
        if result.returncode == 0:
            return True
        else:
            print("Chave SSH inválida ou rejeitada pelo servidor. Código de retorno:", result.returncode)
            if result.stderr.strip():
                print(result.stderr.strip())
            return False
    except Exception as e:
        print(f"Erro ao validar a chave SSH: {e}")
        return False


def establish_ssh_tunnel(server_port, service_port):
    print(f"Iniciando tentativa de conexão SSH na porta {server_port} para o serviço {service_port}...")
    # ExitOnForwardFailure: se o servidor recusar o -R (porta ocupada, por
    # exemplo), o ssh termina em vez de ficar conectado sem túnel.
    # ServerAliveInterval: mantém a conexão viva atrás de NAT/firewall e faz o
    # ssh perceber quando ela cai, em vez de ficar travado.
    ssh_command = [
        *_ssh_base_command(),
        "-o", "ExitOnForwardFailure=yes",
        "-o", "ServerAliveInterval=30",
        "-o", "ServerAliveCountMax=3",
        "-N",
        "-R", f"{server_port}:localhost:{service_port}",
        f"{USERNAME_SSH}@{SERVER_ADDRESS}",
    ]

    try:
        result = subprocess.run(ssh_command, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                universal_newlines=True, creationflags=_no_window())
        if result.stdout.strip():
            print(result.stdout.strip())
        if result.stderr.strip():
            print("Erros do comando SSH:", result.stderr.strip())

        if result.returncode == 0:
            print(f"Túnel SSH encerrado: {SERVER_ADDRESS}:{server_port} -> localhost:{service_port}")
        else:
            print(f"Falha ao estabelecer o túnel SSH. Código de retorno: {result.returncode}")
    except Exception as e:
        print(f"Erro ao tentar estabelecer o túnel SSH: {e}")
    finally:
        with _active_ports_lock:
            _active_ports.discard(server_port)


def verificar_e_abrir_tunel():
    tunnel_info = check_tunnel_request()
    if not tunnel_info:
        return

    # Valida as portas antes de passá-las ao comando SSH, já que vêm de
    # uma resposta HTTP remota.
    try:
        server_port = int(tunnel_info.get("server_port"))
        service_port = int(tunnel_info.get("service_port"))
    except (TypeError, ValueError):
        print("Erro: portas inválidas recebidas do servidor.")
        return

    if not (1 <= server_port <= 65535 and 1 <= service_port <= 65535):
        print(f"Portas fora do intervalo válido: server={server_port}, service={service_port}")
        return

    # O túnel desta porta já está subindo ou aberto
    with _active_ports_lock:
        if server_port in _active_ports:
            return
        _active_ports.add(server_port)

    threading.Thread(target=establish_ssh_tunnel, args=(server_port, service_port), daemon=True).start()


def poll_forever():
    while True:
        try:
            verificar_e_abrir_tunel()
        except Exception as e:
            # O cliente roda como serviço: um erro inesperado não pode derrubá-lo
            print(f"Erro inesperado: {e}")
        time.sleep(POLL_INTERVAL)


def write_private_file(path, content):
    """Grava o arquivo de forma atômica (arquivo temporário + rename)."""
    temporary = path + ".tmp"
    with open(temporary, "w", encoding="utf-8", newline="\n") as f:
        f.write(content)
    os.replace(temporary, path)


def enroll(panel_url, token, config_path):
    """Registra esta máquina no painel com o código do comando de instalação."""
    if hasattr(os, "umask"):
        os.umask(0o077)  # chave, config.json e known_hosts legíveis só pelo usuário do serviço

    config_dir = os.path.dirname(os.path.abspath(config_path))
    os.makedirs(config_dir, exist_ok=True)
    key_path = os.path.join(config_dir, "id_ed25519")
    hostname = socket.gethostname()

    # A chave privada é gerada aqui e nunca sai da máquina. Numa reinstalação, reaproveita a existente.
    if not os.path.exists(key_path):
        print("Gerando a chave SSH desta máquina...")
        result = subprocess.run(
            [ssh_program("ssh-keygen"), "-q", "-t", "ed25519", "-N", "", "-C", f"ssh-tunnel@{hostname}", "-f", key_path],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True,
        )
        if result.returncode != 0:
            print(f"Erro ao gerar a chave SSH: {result.stderr.strip()}")
            return 1

    with open(key_path + ".pub", encoding="utf-8") as f:
        public_key = f.read().strip()

    hardware_id = stable_machine_id()
    print("Registrando a máquina no painel...")
    try:
        status, data = post_json(panel_url.rstrip("/") + "/api/enroll", {
            "token": token,
            "hardware_id": hardware_id,
            "hostname": hostname,
            "os": platform.platform(),
            "public_key": public_key,
        })
    except OSError as e:
        print(f"Erro ao conectar com o painel em {panel_url}: {e}")
        return 1

    if status != 200 or not data.get("success"):
        print(f"Erro ao registrar a máquina no painel (HTTP {status}): {data.get('message', '')}")
        return 1

    ssh = data["ssh"]
    known_hosts = ssh.get("known_hosts") or []
    if known_hosts:
        write_private_file(os.path.join(config_dir, "known_hosts"), "\n".join(known_hosts) + "\n")

    config = {
        "panel_url": panel_url.rstrip("/"),
        "check_endpoint": data["check_endpoint"],
        "hardware_id": hardware_id,
        "ssh_host": ssh["host"],
        "ssh_port": int(ssh.get("port") or 22),
        "ssh_user": ssh["user"],
        "private_key": "id_ed25519",
        "known_hosts": "known_hosts",
    }
    write_private_file(config_path, json.dumps(config, indent=2) + "\n")

    print(f"Máquina registrada no painel como \"{data['machine']['name']}\".")
    return 0


def load_config(config_path):
    global CHECK_TUNNEL_ENDPOINT, USERNAME_SSH, SERVER_ADDRESS, SSH_PORT, PRIVATE_KEY_PATH, KNOWN_HOSTS_PATH, HARDWARE_ID

    with open(config_path, encoding="utf-8") as f:
        config = json.load(f)

    base_dir = os.path.dirname(os.path.abspath(config_path))
    CHECK_TUNNEL_ENDPOINT = config["check_endpoint"]
    SERVER_ADDRESS = config["ssh_host"]
    SSH_PORT = int(config.get("ssh_port", 22))
    USERNAME_SSH = config["ssh_user"]
    PRIVATE_KEY_PATH = os.path.join(base_dir, config.get("private_key", "id_ed25519"))
    KNOWN_HOSTS_PATH = os.path.join(base_dir, config.get("known_hosts", "known_hosts"))
    HARDWARE_ID = config["hardware_id"]


class LogFile:
    """Saída do cliente em arquivo (serviço do Windows), com data e hora em cada linha.

    Ao passar de 5 MB, o arquivo vira <nome>.1 e um novo é aberto.
    """

    MAX_BYTES = 5 * 1024 * 1024

    def __init__(self, path):
        self._path = path
        self._lock = threading.Lock()
        self._at_line_start = True
        self._open()

    def _open(self):
        self._file = open(self._path, "a", encoding="utf-8")

    def _rotate(self):
        # No Windows o rename falha se outro programa estiver com o log aberto
        # (ex.: Get-Content -Wait): nesse caso continua escrevendo no mesmo arquivo.
        self._file.close()
        try:
            os.replace(self._path, self._path + ".1")
        except OSError:
            pass
        self._open()

    def write(self, text):
        with self._lock:
            for part in text.splitlines(keepends=True):
                if self._at_line_start:
                    self._file.write(time.strftime("%Y-%m-%d %H:%M:%S "))
                self._file.write(part)
                self._at_line_start = part.endswith("\n")
            self._file.flush()
            if self._at_line_start and self._file.tell() > self.MAX_BYTES:
                self._rotate()

    def flush(self):
        with self._lock:
            self._file.flush()


def main():
    global HARDWARE_ID

    parser = argparse.ArgumentParser(description="Cliente do ssh-tunnel: abre o túnel SSH reverso pedido no painel.")
    parser.add_argument("--config", help="config.json criado pela instalação pelo painel")
    parser.add_argument("--enroll", nargs=2, metavar=("PAINEL", "CODIGO"),
                        help="registra esta máquina no painel (usado pelos instaladores)")
    parser.add_argument("--log", help="grava a saída neste arquivo em vez do console")
    args = parser.parse_args()

    if args.log:
        sys.stdout = sys.stderr = LogFile(args.log)

    if args.enroll:
        if not args.config:
            parser.error("--enroll precisa de --config")
        sys.exit(enroll(args.enroll[0], args.enroll[1], args.config))

    if args.config:
        # Instalado pelo painel: o instalador já cuidou da chave e das permissões
        load_config(args.config)
        print(f"Cliente iniciado. Hardware ID: {HARDWARE_ID}")
        if check_private_key() and not is_valid_ssh_key():
            print("Aviso: o servidor recusou a chave SSH desta máquina. As próximas solicitações vão tentar de novo.")
        poll_forever()

    HARDWARE_ID = get_hardware_id()
    print(f"Hardware ID: {HARDWARE_ID}")

    if platform.system() == 'Windows' and not is_admin():
        print("Este script precisa ser executado como administrador no Windows.")
        sys.exit(1)

    set_private_key_permissions()

    if not is_valid_ssh_key():
        print("A chave SSH é inválida. Encerrando o script.")
        sys.exit(1)

    poll_forever()

if __name__ == "__main__":
    main()
