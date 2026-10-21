#!/usr/bin/env bash
# Instalador do cliente ssh-tunnel para Linux, gerado pelo painel em
# "Instalar em Nova Máquina". O código abaixo vale para uma única máquina.
#
#   curl -fsSL <painel>/instalar/<código>/linux | sudo bash
#
# Instala o python3 e o cliente OpenSSH (se faltarem), baixa o cliente para
# /opt/ssh-tunnel-client, registra esta máquina no painel e deixa o cliente
# rodando como serviço do systemd (ssh-tunnel-client).
set -euo pipefail

PANEL_URL='__PANEL_URL__'
ENROLL_TOKEN='__TOKEN__'

export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# O programa fica numa pasta do root; o serviço só escreve em dados/ (chave, config.json, known_hosts)
INSTALL_DIR=/opt/ssh-tunnel-client
STATE_DIR=$INSTALL_DIR/dados
SERVICE_USER=ssh-tunnel-client
SERVICE_NAME=ssh-tunnel-client

info() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31mErro:\033[0m %s\n' "$*" >&2; exit 1; }

download() {
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL "$1" -o "$2"
    else
        wget -qO "$2" "$1"
    fi
}

run_as_service_user() {
    if command -v runuser >/dev/null 2>&1; then
        runuser -u "$SERVICE_USER" -- "$@"
    else
        sudo -u "$SERVICE_USER" -- "$@"
    fi
}

install_dependencies() {
    command -v python3 >/dev/null 2>&1 && command -v ssh >/dev/null 2>&1 && command -v ssh-keygen >/dev/null 2>&1 && return

    info "Instalando python3 e o cliente OpenSSH..."
    if command -v apt-get >/dev/null 2>&1; then
        apt-get update -qq
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq python3 openssh-client >/dev/null
    elif command -v dnf >/dev/null 2>&1; then
        dnf install -y -q python3 openssh-clients
    elif command -v yum >/dev/null 2>&1; then
        yum install -y -q python3 openssh-clients
    elif command -v zypper >/dev/null 2>&1; then
        zypper --non-interactive --quiet install python3 openssh-clients
    elif command -v pacman >/dev/null 2>&1; then
        pacman -Sy --noconfirm --needed python openssh
    else
        fail "instale python3 e o cliente OpenSSH (ssh e ssh-keygen) e rode o comando de novo."
    fi
}

write_service() {
    cat > "/etc/systemd/system/$SERVICE_NAME.service" <<EOF
[Unit]
Description=Cliente ssh-tunnel (túnel SSH reverso)
Wants=network-online.target
After=network-online.target

[Service]
User=$SERVICE_USER
WorkingDirectory=$STATE_DIR
ExecStart=/usr/bin/env python3 -u $INSTALL_DIR/script_cliente.py --config $STATE_DIR/config.json
Restart=always
RestartSec=10
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=$STATE_DIR

[Install]
WantedBy=multi-user.target
EOF
}

# Tudo roda dentro de main(): com "curl | bash", o bash só começa a executar
# depois de ler o script inteiro, e nenhum comando consome o resto do script.
main() {
    [ "$(id -u)" -eq 0 ] || fail "rode o comando com sudo: curl -fsSL ... | sudo bash"
    command -v systemctl >/dev/null 2>&1 || fail "este instalador precisa do systemd."

    # 1. Dependências
    install_dependencies

    # Para uma instalação anterior antes de mexer nos arquivos
    systemctl stop "$SERVICE_NAME" >/dev/null 2>&1 || true

    # 2. Usuário de sistema que roda o cliente, sem login nem senha
    if ! id -u "$SERVICE_USER" >/dev/null 2>&1; then
        info "Criando o usuário de sistema $SERVICE_USER..."
        useradd --system --home-dir "$STATE_DIR" --no-create-home \
            --shell "$(command -v nologin || echo /bin/false)" "$SERVICE_USER"
    fi
    install -d -m 0755 -o root -g root "$INSTALL_DIR"
    install -d -m 0700 -o "$SERVICE_USER" -g "$SERVICE_USER" "$STATE_DIR"

    # 3. Cliente: baixado numa pasta temporária do root e instalado como arquivo do root,
    #    que o serviço não consegue alterar
    info "Baixando o cliente..."
    DOWNLOAD_DIR=$(mktemp -d)
    trap 'rm -rf "$DOWNLOAD_DIR"' EXIT
    download "$PANEL_URL/instalar/cliente.py" "$DOWNLOAD_DIR/script_cliente.py"
    python3 -c 'import ast, sys; ast.parse(open(sys.argv[1], "rb").read())' "$DOWNLOAD_DIR/script_cliente.py" \
        || fail "o arquivo baixado de $PANEL_URL/instalar/cliente.py não é o cliente."
    install -m 0644 -o root -g root "$DOWNLOAD_DIR/script_cliente.py" "$INSTALL_DIR/script_cliente.py"

    # 4. Registro no painel: gera a chave SSH desta máquina e cadastra a máquina
    (cd "$STATE_DIR" && run_as_service_user python3 "$INSTALL_DIR/script_cliente.py" \
        --config "$STATE_DIR/config.json" --enroll "$PANEL_URL" "$ENROLL_TOKEN" </dev/null) \
        || fail "não foi possível registrar a máquina no painel (veja a mensagem acima)."

    # 5. Serviço do systemd: sobe junto com a máquina e reinicia se cair
    info "Configurando o serviço $SERVICE_NAME..."
    write_service
    systemctl daemon-reload
    systemctl enable "$SERVICE_NAME" >/dev/null 2>&1
    systemctl restart "$SERVICE_NAME"

    info "Pronto! A máquina já aparece no painel e o cliente está rodando."
    echo "    Ver o log:     journalctl -u $SERVICE_NAME -f"
    echo "    Desinstalar:   systemctl disable --now $SERVICE_NAME && rm -rf $INSTALL_DIR /etc/systemd/system/$SERVICE_NAME.service"
}

main "$@"
