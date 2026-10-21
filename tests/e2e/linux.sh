#!/usr/bin/env bash
# Teste de ponta a ponta no Linux (roda no GitHub Actions, numa VM descartável):
# instala o servidor com o install.sh, gera o comando de instalação no painel,
# instala o cliente na mesma máquina e abre um túnel de verdade até um serviço
# HTTP local, conferindo também as restrições do usuário dos túneis.
#
# Precisa de root (sudo) e altera o sistema: não rode na sua máquina.
set -euo pipefail

REPO_DIR=$(cd "$(dirname "$0")/../.." && pwd)
PANEL=http://127.0.0.1
EMAIL=e2e@exemplo.com
PASSWORD=SenhaDeTeste123
JAR=$(mktemp)
SITE=$(mktemp -d)

step() { printf '\n\033[1;34m### %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFALHOU:\033[0m %s\n' "$*" >&2; dump_logs; exit 1; }
dump_logs() {
    set +e
    echo "--- log do cliente"; sudo journalctl -u ssh-tunnel-client --no-pager -n 50
    echo "--- log do monitor"; sudo journalctl -u ssh-tunnel-monitor --no-pager -n 30
    echo "--- log do sshd"; sudo journalctl -u ssh --no-pager -n 30
    echo "--- log do Laravel"; sudo tail -n 50 /var/www/ssh-tunnel/storage/logs/laravel.log
}
# Consulta direta ao banco (o root do MariaDB entra pelo socket, sem senha)
sql() { sudo mysql -N -B ssh_tunnel -e "$1"; }

# Retorna o token CSRF da última página baixada
csrf() { grep -oP '<meta name="csrf-token" content="\K[^"]+' "$1"; }

step "Instala o servidor"
sudo ORIGEM="$REPO_DIR" DOMINIO=127.0.0.1 EMAIL=admin@exemplo.com bash "$REPO_DIR/install.sh"
curl -fsS -o /dev/null "$PANEL/up" || fail "o painel não respondeu em $PANEL/up"
systemctl is-active --quiet ssh-tunnel-monitor || fail "o monitor de túneis não está rodando"

step "O PHP tem a correção da PHP2Shell (CVE-2026-109618 e CVE-2026-109619)"
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
PHP_FPM_VERSION=$(dpkg-query -W -f='${Version}' "php$PHP_VERSION-fpm")
echo "php$PHP_VERSION-fpm $PHP_FPM_VERSION"
# Ubuntu 24.04: corrigido em 8.3.6-0ubuntu0.24.04.13 (USN-8948-1)
[ "$PHP_VERSION" != 8.3 ] || dpkg --compare-versions "$PHP_FPM_VERSION" ge 8.3.6-0ubuntu0.24.04.13 \
    || fail "o PHP-FPM instalado ($PHP_FPM_VERSION) ainda não tem a correção da PHP2Shell"
UPLOAD_PROGRESS=$(sudo "php-fpm$PHP_VERSION" -i | grep '^session.upload_progress.enabled =>' || true)
echo "$UPLOAD_PROGRESS"
[[ $UPLOAD_PROGRESS == *"=> Off =>"* ]] || fail "o progresso de upload da sessão continua ligado no PHP-FPM"

step "Entra no painel"
sudo -u www-data php /var/www/ssh-tunnel/artisan user:create "$EMAIL" --password="$PASSWORD"
curl -fsS -c "$JAR" -b "$JAR" "$PANEL/login" -o /tmp/login.html
curl -fsS -c "$JAR" -b "$JAR" -o /dev/null -X POST "$PANEL/login" \
    --data-urlencode "_token=$(csrf /tmp/login.html)" --data-urlencode "email=$EMAIL" --data-urlencode "password=$PASSWORD"
curl -fsS -c "$JAR" -b "$JAR" "$PANEL/dashboard" -o /tmp/dashboard.html
grep -q 'Instalar em Nova Máquina' /tmp/dashboard.html || fail "o login não chegou ao dashboard"
TOKEN=$(csrf /tmp/dashboard.html)

api() { # api MÉTODO CAMINHO [JSON]
    curl -fsS -c "$JAR" -b "$JAR" -X "$1" "$PANEL$2" -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -H "X-CSRF-TOKEN: $TOKEN" ${3:+--data "$3"}
}

step "Cadastra um cliente e gera o comando de instalação"
curl -fsS -c "$JAR" -b "$JAR" -o /dev/null -X POST "$PANEL/clients" --data-urlencode "_token=$TOKEN" --data-urlencode "name=Cliente E2E"
CLIENT_ID=$(sql "SELECT id FROM clients WHERE name = 'Cliente E2E'")
COMMAND=$(api POST "/clients/$CLIENT_ID/enrollment-tokens" | jq -r .commands.linux)
echo "$COMMAND"
[[ $COMMAND == "curl -fsSL $PANEL/instalar/"*"/linux | sudo bash" ]] || fail "comando de instalação inesperado"

step "Instala o cliente com o comando do painel"
bash -c "$COMMAND"
systemctl is-active --quiet ssh-tunnel-client || fail "o serviço do cliente não está rodando"
MACHINE_ID=$(sql "SELECT MAX(id) FROM machines")
[ -n "$MACHINE_ID" ] && [ "$MACHINE_ID" != NULL ] || fail "a máquina não apareceu no painel"
sudo test -s /opt/ssh-tunnel-client/dados/known_hosts || fail "o cliente não recebeu o known_hosts do servidor"
[ "$(sudo stat -c %a /opt/ssh-tunnel-client/dados/id_ed25519)" = 600 ] || fail "a chave privada do cliente não está com permissão 600"

step "Abre um túnel até um serviço HTTP local"
echo tunel-ok > "$SITE/index.html"
python3 -m http.server 8080 --bind 127.0.0.1 --directory "$SITE" >/dev/null 2>&1 &
SERVER_PORT=$(api POST "/machines/$MACHINE_ID/open-tunnel" '{"service_port": 8080}' | jq -r .server_port)
echo "porta do túnel: $SERVER_PORT"

for _ in $(seq 1 30); do
    [ "$(curl -s --max-time 2 "http://127.0.0.1:$SERVER_PORT/" || true)" = tunel-ok ] && break
    sleep 2
done
[ "$(curl -s --max-time 2 "http://127.0.0.1:$SERVER_PORT/")" = tunel-ok ] || fail "o túnel não abriu"

# O túnel escuta em todas as interfaces (GatewayPorts), não só no localhost
HOST_IP=$(hostname -I | awk '{print $1}')
[ "$(curl -s --max-time 2 "http://$HOST_IP:$SERVER_PORT/")" = tunel-ok ] || fail "o túnel não está acessível pelo IP do servidor ($HOST_IP)"

for _ in $(seq 1 15); do
    [ "$(api GET "/machines/$MACHINE_ID/tunnel-status" | jq -r .status)" = in_progress ] && break
    sleep 2
done
[ "$(api GET "/machines/$MACHINE_ID/tunnel-status" | jq -r .status)" = in_progress ] || fail "o monitor não marcou o túnel como ativo"

step "Restrições do usuário dos túneis"
client_ssh() {
    sudo -u ssh-tunnel-client timeout 20 ssh -i /opt/ssh-tunnel-client/dados/id_ed25519 \
        -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes \
        -o UserKnownHostsFile=/opt/ssh-tunnel-client/dados/known_hosts "$@"
}

# Sem shell: qualquer comando vira /bin/true
OUTPUT=$(client_ssh tunnel@127.0.0.1 'id -u; echo shell-aberto' 2>&1 || true)
[[ $OUTPUT != *shell-aberto* ]] || fail "o usuário dos túneis conseguiu rodar comandos: $OUTPUT"

# Só pode abrir túnel na porta reservada para esta máquina
set +e
OUTPUT=$(client_ssh -o ExitOnForwardFailure=yes -N -R 45999:localhost:8080 tunnel@127.0.0.1 2>&1)
STATUS=$?
set -e
[ "$STATUS" -ne 124 ] || fail "o ssh -R numa porta não reservada ficou conectado"
[[ $OUTPUT == *"remote port forwarding failed"* ]] || fail "o ssh -R numa porta não reservada não foi recusado: $OUTPUT"

# Chave desconhecida não entra
ssh-keygen -q -t ed25519 -N '' -f /tmp/chave-estranha
OUTPUT=$(timeout 20 ssh -i /tmp/chave-estranha -o BatchMode=yes -o StrictHostKeyChecking=no -N -R "$SERVER_PORT:localhost:8080" tunnel@127.0.0.1 2>&1 || true)
[[ $OUTPUT == *"Permission denied"* ]] || fail "uma chave não cadastrada foi aceita: $OUTPUT"

step "Fecha o túnel pelo painel"
api POST "/machines/$MACHINE_ID/close-tunnel" | jq -e .success >/dev/null || fail "o painel não fechou o túnel"
sleep 3
[ "$(curl -s --max-time 2 "http://127.0.0.1:$SERVER_PORT/" || true)" != tunel-ok ] || fail "o túnel continua aberto depois de fechado"
[ "$(api GET "/machines/$MACHINE_ID/tunnel-status" | jq -r .status)" = completed ] || fail "o status não ficou 'completed'"

step "Rodar o install.sh de novo atualiza sem quebrar nada"
sudo ORIGEM="$REPO_DIR" bash "$REPO_DIR/install.sh"
curl -fsS -o /dev/null "$PANEL/up" || fail "o painel parou depois da atualização"
[ "$(sql 'SELECT COUNT(*) FROM machines')" = 1 ] || fail "a atualização perdeu dados"

step "Tudo certo"
