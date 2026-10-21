#!/usr/bin/env bash
# Instalador do servidor ssh-tunnel (painel web + túneis) para Ubuntu 24.04+ e Debian 12+.
#
#   curl -fsSL https://raw.githubusercontent.com/Ciccotti/ssh-tunnel/master/install.sh | sudo bash
#
# Pergunta o domínio (ou IP) do servidor e um e-mail, e instala e configura:
# Nginx com HTTPS (Let's Encrypt), PHP, MariaDB, o painel, o usuário SSH dos
# túneis, o firewall (ufw), as permissões de sudo do painel e o monitor de túneis.
# No fim mostra o endereço do painel e a senha do primeiro usuário. Ao informar
# o e-mail com HTTPS, você aceita os termos de uso do Let's Encrypt.
#
# Rodar de novo atualiza o painel para a versão mais recente, mantendo o .env,
# o banco de dados e o certificado.
#
# Para rodar sem perguntas, defina as variáveis antes do bash:
#   curl -fsSL .../install.sh | sudo DOMINIO=painel.exemplo.com EMAIL=voce@exemplo.com bash
#
#   DOMINIO   domínio ou IP público do servidor
#   EMAIL     e-mail do primeiro usuário do painel e do Let's Encrypt
#   HTTPS     sim | nao (padrão: sim para domínio, nao para IP)
#   REPO      repositório git (padrão: https://github.com/Ciccotti/ssh-tunnel.git)
#   BRANCH    branch (padrão: master)
#   ORIGEM    pasta com o código, em vez de baixar do git (usado nos testes)
set -euo pipefail

# Com "su" (sem sudo) o Debian não põe /usr/sbin no PATH, onde ficam useradd, sshd, ufw...
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

APP_DIR=/var/www/ssh-tunnel
WEB_USER=www-data
TUNNEL_USER=tunnel
KEYS_COMMAND=/usr/libexec/ssh-tunnel/authorized-keys
REPO=${REPO:-https://github.com/Ciccotti/ssh-tunnel.git}
BRANCH=${BRANCH:-master}

# Node.js só para compilar o CSS/JS do painel (nodejs.org, conferido pelo SHA-256)
NODE_VERSION=22.23.3
NODE_SHA256_x64=df450af89261115ef9f9e3830c3eeb2cc9213b63c720b1af623cb5dcbe2e02de
NODE_SHA256_arm64=a44aeb94849a299b22df10b9e622ec2f605c2183501bc40590705131de7c740f

info() { printf '\n\033[1;34m==>\033[0m \033[1m%s\033[0m\n' "$*"; }
warn() { printf '\033[1;33mAviso:\033[0m %s\n' "$*" >&2; }
fail() { printf '\033[1;31mErro:\033[0m %s\n' "$*" >&2; exit 1; }

# Pergunta no terminal mesmo com "curl | bash" (a entrada padrão é o script)
ask() {
    local var=$1 prompt=$2 default=${3:-} value
    [ -n "${!var:-}" ] && return
    if (exec </dev/tty) 2>/dev/null; then
        read -r -p "$prompt${default:+ [$default]}: " value </dev/tty
        value=${value:-$default}
    else
        value=$default
    fi
    [ -n "$value" ] || fail "defina $var. Exemplo: curl -fsSL .../install.sh | sudo $var=... bash"
    printf -v "$var" '%s' "$value"
}

is_ip() { [[ $1 =~ ^[0-9]+(\.[0-9]+){3}$ || $1 == *:* ]]; }

as_web() { runuser -u "$WEB_USER" -- "$@"; }

artisan() { (cd "$APP_DIR" && as_web php artisan "$@"); }

# Define (ou acrescenta) uma variável no .env
set_env() {
    local key=$1 value=$2 file=$APP_DIR/.env
    if grep -q "^$key=" "$file"; then
        sed -i "s|^$key=.*|$key=${value//|/\\|}|" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

random_secret() { tr -dc 'A-Za-z0-9' </dev/urandom | head -c "${1:-32}" || true; }

check_system() {
    [ "$(id -u)" -eq 0 ] || fail "rode como root: curl -fsSL .../install.sh | sudo bash"
    [ -r /etc/os-release ] || fail "sistema não suportado (use Ubuntu 24.04+ ou Debian 12+)."
    . /etc/os-release
    case "$ID" in
        ubuntu) dpkg --compare-versions "$VERSION_ID" ge 24.04 || fail "use Ubuntu 24.04 ou mais novo (este é o $VERSION_ID)." ;;
        debian) dpkg --compare-versions "$VERSION_ID" ge 12 || fail "use Debian 12 ou mais novo (este é o $VERSION_ID)." ;;
        *) fail "sistema não suportado: $PRETTY_NAME (use Ubuntu 24.04+ ou Debian 12+)." ;;
    esac
}

env_value() { [ -f "$APP_DIR/.env" ] && sed -n "s/^$1=//p" "$APP_DIR/.env" | tail -n1 || true; }

# Quantos usuários o painel tem (0 também quando o banco ainda não existe)
user_count() {
    local db count
    db=$(env_value DB_DATABASE)
    count=$( { [ -n "$db" ] && mysql -N -B -e 'SELECT COUNT(*) FROM users' "$db"; } 2>/dev/null || true)
    echo "${count:-0}"
}

has_certificate() { [ -d "/etc/letsencrypt/live/$DOMINIO" ]; }

ask_settings() {
    # NEW_ENV: ainda não há .env (instalação nova). Os outros passos são decididos pelo
    # estado do servidor, para que uma instalação interrompida possa ser retomada.
    NEW_ENV=1
    [ -f "$APP_DIR/.env" ] && NEW_ENV=0
    CURRENT_HOST=$(env_value TUNNEL_SERVER_HOST)

    if [ -n "$CURRENT_HOST" ]; then
        DOMINIO=${DOMINIO:-$CURRENT_HOST}
        info "Atualizando a instalação existente em $APP_DIR ($DOMINIO)"
    else
        info "Instalação do servidor ssh-tunnel"
        ask DOMINIO "Domínio do painel (ou o IP público do servidor)" "$(hostname -I 2>/dev/null | awk '{print $1}')"
    fi
    DOMAIN_CHANGED=0
    [ -n "$CURRENT_HOST" ] && [ "$DOMINIO" != "$CURRENT_HOST" ] && DOMAIN_CHANGED=1

    if [ -z "${HTTPS:-}" ]; then
        if is_ip "$DOMINIO"; then HTTPS=nao; else HTTPS=sim; fi
    fi

    # O e-mail só é necessário para criar o primeiro usuário ou emitir o certificado
    if [ "$(user_count)" = 0 ] || { [ "$HTTPS" = sim ] && ! has_certificate; }; then
        ask EMAIL "Seu e-mail (login do painel e aviso do certificado HTTPS)"
    fi

    if is_ip "$DOMINIO"; then
        warn "sem um domínio o painel funciona só com HTTP. Para HTTPS, aponte um domínio para este servidor e rode de novo com DOMINIO=seu.dominio."
    fi
}

install_packages() {
    info "Instalando os pacotes do sistema..."
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    local packages=(nginx mariadb-server php-fpm php-cli php-mysql php-mbstring php-xml php-curl php-zip
        php-bcmath php-intl php-sqlite3 git curl unzip xz-utils rsync ufw openssh-server lsof sudo ca-certificates)
    [ "$HTTPS" = sim ] && packages+=(certbot python3-certbot-nginx)
    apt-get install -y -qq "${packages[@]}" >/dev/null

    php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || fail "o painel precisa do PHP 8.2 ou mais novo."
    PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')

    if [ ! -x /usr/local/bin/composer ]; then
        info "Instalando o Composer..."
        curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar -o /tmp/composer.phar
        echo "$(curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar.sha256)  /tmp/composer.phar" | sha256sum -c --quiet \
            || fail "o Composer baixado não confere com o SHA-256 publicado."
        install -m 0755 /tmp/composer.phar /usr/local/bin/composer
        rm -f /tmp/composer.phar
    fi
}

install_code() {
    info "Baixando o painel para $APP_DIR..."
    mkdir -p "$APP_DIR"
    if [ -n "${ORIGEM:-}" ]; then
        rsync -a --delete --exclude .git --exclude .env --exclude vendor --exclude node_modules \
            --exclude 'storage/logs/*' --exclude 'storage/framework/*/*' --exclude 'public/build' --exclude 'public/storage' \
            "$ORIGEM/" "$APP_DIR/"
    elif [ -d "$APP_DIR/.git" ]; then
        git -C "$APP_DIR" fetch --quiet --depth 1 origin "$BRANCH"
        git -C "$APP_DIR" reset --quiet --hard FETCH_HEAD
    else
        git clone --quiet --depth 1 --branch "$BRANCH" "$REPO" "$APP_DIR"
    fi

    # Código do root (o PHP só lê); o PHP escreve apenas em storage e bootstrap/cache
    chown -R root:root "$APP_DIR"
    chown -R "$WEB_USER:$WEB_USER" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
}

configure_database() {
    systemctl enable --now mariadb >/dev/null 2>&1
    [ "$NEW_ENV" = 1 ] || return 0

    info "Criando o banco de dados..."
    DB_PASSWORD=$(random_secret)
    mysql <<SQL
CREATE DATABASE IF NOT EXISTS ssh_tunnel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ssh_tunnel'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER 'ssh_tunnel'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON ssh_tunnel.* TO 'ssh_tunnel'@'localhost';
FLUSH PRIVILEGES;
SQL
}

# Portas do SSH: as do sshd_config e as que estão escutando agora (o firewall não pode
# bloquear a sessão de quem está instalando)
ssh_ports() {
    mkdir -p /run/sshd
    {
        sshd -T 2>/dev/null | awk '$1 == "port" {print $2}'
        ss -Htlnp 2>/dev/null | awk '/"sshd"/ {sub(/.*:/, "", $4); print $4}'
    } | grep -E '^[0-9]+$' | sort -un || true
}

first_ssh_port() { local port; port=$(ssh_ports | head -n1); echo "${port:-22}"; }

configure_env() {
    if [ "$NEW_ENV" = 1 ]; then
        cp "$APP_DIR/.env.example" "$APP_DIR/.env"
        set_env APP_NAME ssh-tunnel
        set_env APP_ENV production
        set_env APP_DEBUG false
        set_env APP_URL "http://$DOMINIO"
        set_env LOG_LEVEL warning
        set_env DB_CONNECTION mariadb
        set_env DB_HOST localhost
        set_env DB_PORT 3306
        set_env DB_DATABASE ssh_tunnel
        set_env DB_USERNAME ssh_tunnel
        set_env DB_PASSWORD "$DB_PASSWORD"
        set_env SESSION_SECURE_COOKIE false
        set_env TUNNEL_SERVER_HOST "$DOMINIO"
        set_env TUNNEL_SSH_USER "$TUNNEL_USER"
        set_env TUNNEL_SSH_PORT "$(first_ssh_port)"
    elif [ "$DOMAIN_CHANGED" = 1 ]; then
        # Mudou de domínio (ex.: de IP para um domínio, para ter HTTPS). O certificado
        # novo, se houver, volta o APP_URL para https em configure_nginx.
        set_env APP_URL "http://$DOMINIO"
        set_env SESSION_SECURE_COOKIE false
        set_env TUNNEL_SERVER_HOST "$DOMINIO"
    fi

    # Variáveis novas de versões mais recentes do painel
    grep -q '^TUNNEL_SSH_USER=' "$APP_DIR/.env" || set_env TUNNEL_SSH_USER "$TUNNEL_USER"
    grep -q '^TUNNEL_SSH_PORT=' "$APP_DIR/.env" || set_env TUNNEL_SSH_PORT "$(first_ssh_port)"

    chown root:"$WEB_USER" "$APP_DIR/.env"
    chmod 0640 "$APP_DIR/.env"
}

install_node() {
    local arch
    case "$(uname -m)" in
        x86_64) arch=x64 ;;
        aarch64) arch=arm64 ;;
        *) fail "arquitetura não suportada: $(uname -m)." ;;
    esac

    NODE_DIR=/usr/local/lib/ssh-tunnel-node-$NODE_VERSION
    [ -x "$NODE_DIR/bin/node" ] && return

    local sha_var=NODE_SHA256_$arch file=node-v$NODE_VERSION-linux-$arch.tar.xz
    curl -fsSL "https://nodejs.org/dist/v$NODE_VERSION/$file" -o "/tmp/$file"
    echo "${!sha_var}  /tmp/$file" | sha256sum -c --quiet || fail "o Node.js baixado não confere com o SHA-256 esperado."
    mkdir -p "$NODE_DIR"
    tar -xJf "/tmp/$file" -C "$NODE_DIR" --strip-components=1
    rm -f "/tmp/$file"
}

build_app() {
    info "Instalando as dependências do painel..."
    # Caches da versão anterior (apagados sem o artisan, que carregaria o código novo com o vendor antigo)
    rm -f "$APP_DIR"/bootstrap/cache/{config,routes-v7,events,packages,services}.php
    (cd "$APP_DIR" && COMPOSER_ALLOW_SUPERUSER=1 composer install --quiet --no-dev --optimize-autoloader --no-interaction --no-scripts)
    chown -R "$WEB_USER:$WEB_USER" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
    artisan package:discover --quiet

    grep -q '^APP_KEY=base64:' "$APP_DIR/.env" || (cd "$APP_DIR" && php artisan key:generate --force --quiet)

    info "Compilando o CSS e o JavaScript do painel..."
    install_node
    (cd "$APP_DIR" && PATH="$NODE_DIR/bin:$PATH" npm ci --silent --no-audit --no-fund >/dev/null \
        && PATH="$NODE_DIR/bin:$PATH" npm run build --silent >/dev/null)
    rm -rf "$APP_DIR/node_modules"

    info "Atualizando o banco de dados..."
    artisan migrate --force --quiet
    [ -e "$APP_DIR/public/storage" ] || (cd "$APP_DIR" && php artisan storage:link --quiet)
}

configure_tunnel_ssh() {
    info "Configurando o SSH dos túneis..."

    # Usuário que recebe o "ssh -R" das máquinas clientes. A senha "*" desativa o
    # login por senha sem bloquear a conta (o login é só por chave).
    if ! id -u "$TUNNEL_USER" >/dev/null 2>&1; then
        useradd --system --create-home --home-dir "/home/$TUNNEL_USER" --shell /bin/sh "$TUNNEL_USER"
    fi
    usermod -p '*' "$TUNNEL_USER"

    # O sshd pergunta ao painel quais chaves aceitar (cada máquina tem a sua). Ele só
    # roda o comando se o arquivo e todas as pastas acima dele forem do root e não
    # puderem ser alteradas por outros usuários.
    install -d -m 0755 -o root -g root "$(dirname "$KEYS_COMMAND")"
    cat > "$KEYS_COMMAND" <<EOF
#!/bin/sh
# Chaves das máquinas clientes cadastradas no painel (gerado pelo install.sh do ssh-tunnel)
exec /usr/bin/php $APP_DIR/artisan tunnel:authorized-keys "\$1" 2>/dev/null
EOF
    chown root:root "$KEYS_COMMAND"
    chmod 0755 "$KEYS_COMMAND"
    rm -f /usr/local/bin/ssh-tunnel-authorized-keys

    local dir=$KEYS_COMMAND
    while [ "$dir" != / ]; do
        [ "$(stat -c '%u' "$dir")" = 0 ] && [ $(( 0$(stat -c '%a' "$dir") & 022 )) = 0 ] \
            || fail "$dir precisa ser do root e sem permissão de escrita para grupo/outros (exigência do sshd para $KEYS_COMMAND)."
        dir=$(dirname "$dir")
    done

    # O usuário dos túneis só pode fazer "ssh -R" para porta TCP: sem shell, sem -L,
    # sem encaminhar sockets Unix (ex.: o do MariaDB), sem senha
    mkdir -p /etc/ssh/sshd_config.d
    cat > /etc/ssh/sshd_config.d/ssh-tunnel.conf <<EOF
# Usuário dos túneis do ssh-tunnel (gerado pelo install.sh)
Match User $TUNNEL_USER
    AuthorizedKeysCommand $KEYS_COMMAND %u
    AuthorizedKeysCommandUser $WEB_USER
    PasswordAuthentication no
    KbdInteractiveAuthentication no
    AllowTcpForwarding remote
    AllowStreamLocalForwarding no
    GatewayPorts yes
    PermitTunnel no
    PermitTTY no
    PermitUserRC no
    X11Forwarding no
    AllowAgentForwarding no
    ForceCommand /bin/true
EOF
    mkdir -p /run/sshd
    if ! sshd -t; then
        rm -f /etc/ssh/sshd_config.d/ssh-tunnel.conf
        fail "a configuração do sshd ficou inválida e foi desfeita."
    fi
    grep -qE '^\s*Include\s+/etc/ssh/sshd_config.d/' /etc/ssh/sshd_config \
        || warn "o /etc/ssh/sshd_config não inclui /etc/ssh/sshd_config.d/*.conf: copie o conteúdo de /etc/ssh/sshd_config.d/ssh-tunnel.conf para o fim dele."
    systemctl enable ssh >/dev/null 2>&1 || true
    systemctl is-active --quiet ssh || systemctl start ssh
    systemctl reload ssh
}

configure_sudo() {
    # O painel e o monitor liberam/fecham a porta do túnel no ufw e encerram o processo do túnel
    local ufw lsof kill
    ufw=$(command -v ufw) lsof=$(command -v lsof) kill=$(command -v kill)
    [ "$kill" = kill ] && kill=/usr/bin/kill
    printf '# Comandos que o painel ssh-tunnel roda com sudo (gerado pelo install.sh)\n%s ALL=(root) NOPASSWD: %s, %s, %s\n' \
        "$WEB_USER" "$ufw" "$lsof" "$kill" > /tmp/ssh-tunnel.sudoers
    visudo -cqf /tmp/ssh-tunnel.sudoers || fail "a regra de sudo gerada é inválida."
    install -m 0440 -o root -g root /tmp/ssh-tunnel.sudoers /etc/sudoers.d/ssh-tunnel
    rm -f /tmp/ssh-tunnel.sudoers
}

configure_firewall() {
    local port ports
    ports=$(ssh_ports)
    [ -n "$ports" ] || ports=22
    info "Configurando o firewall (ufw): liberando SSH (porta $(echo $ports | tr ' ' ',')), HTTP e HTTPS..."
    for port in $ports; do
        ufw allow "$port/tcp" comment 'SSH' >/dev/null
    done
    ufw allow 80/tcp comment 'HTTP' >/dev/null
    ufw allow 443/tcp comment 'HTTPS' >/dev/null
    # As portas dos túneis (40000-60000) são liberadas pelo painel, só para o IP de quem pediu
    ufw --force enable >/dev/null
}

configure_nginx() {
    local site=/etc/nginx/sites-available/ssh-tunnel site_written=0
    if [ ! -f "$site" ] || [ "$DOMAIN_CHANGED" = 1 ]; then
        site_written=1
        info "Configurando o Nginx..."
        local server_name=$DOMINIO listen_options=""
        if is_ip "$DOMINIO"; then
            server_name=_
            listen_options=" default_server"
        fi
        cat > "$site" <<EOF
# Painel ssh-tunnel (gerado pelo install.sh)
server {
    listen 80$listen_options;
    listen [::]:80$listen_options;
    server_name $server_name;
    root $APP_DIR/public;
    index index.php;
    client_max_body_size 10m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php$PHP_VERSION-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF
        ln -sf "$site" /etc/nginx/sites-enabled/ssh-tunnel
        rm -f /etc/nginx/sites-enabled/default
    fi
    nginx -t -q || fail "a configuração do Nginx ficou inválida."
    systemctl enable --now nginx "php$PHP_VERSION-fpm" >/dev/null 2>&1
    systemctl reload nginx
    systemctl restart "php$PHP_VERSION-fpm"

    # Certificado: quando ainda não existe ou quando o site do Nginx foi recriado
    if [ "$HTTPS" = sim ] && { [ "$site_written" = 1 ] || ! has_certificate; }; then
        info "Configurando o certificado HTTPS (Let's Encrypt)..."
        local account=(--register-unsafely-without-email)
        [ -n "${EMAIL:-}" ] && account=(-m "$EMAIL")
        if certbot --nginx --non-interactive --agree-tos --redirect --keep-until-expiring "${account[@]}" -d "$DOMINIO"; then
            set_env APP_URL "https://$DOMINIO"
            set_env SESSION_SECURE_COOKIE true
        else
            warn "não foi possível emitir o certificado. Confira se o domínio $DOMINIO aponta para este servidor e rode: certbot --nginx -d $DOMINIO"
        fi
    fi
}

configure_monitor() {
    info "Configurando o monitor de túneis..."
    cat > /etc/systemd/system/ssh-tunnel-monitor.service <<EOF
[Unit]
Description=Monitor de túneis do ssh-tunnel
After=network.target mariadb.service

[Service]
User=$WEB_USER
WorkingDirectory=$APP_DIR
ExecStart=/usr/bin/php $APP_DIR/scripts/monitor_tunnels.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable ssh-tunnel-monitor >/dev/null 2>&1
    systemctl restart ssh-tunnel-monitor
}

create_first_user() {
    ADMIN_PASSWORD=""
    [ "$(user_count)" = 0 ] || return 0
    ADMIN_PASSWORD=$(random_secret 16)
    artisan user:create "$EMAIL" --password="$ADMIN_PASSWORD" >/dev/null
}

# Tudo roda dentro de main(): com "curl | bash", o bash só começa a executar
# depois de ler o script inteiro.
main() {
    check_system
    ask_settings
    install_packages
    install_code
    configure_database
    configure_env
    build_app
    configure_tunnel_ssh
    configure_sudo
    configure_firewall
    configure_nginx
    configure_monitor
    create_first_user
    artisan optimize --quiet

    local url
    url=$(sed -n 's/^APP_URL=//p' "$APP_DIR/.env")
    info "Pronto! O painel está em $url"
    if [ -n "$ADMIN_PASSWORD" ]; then
        echo "    Login:  $EMAIL"
        echo "    Senha:  $ADMIN_PASSWORD   (troque em Perfil depois de entrar)"
    fi
    echo "    Para instalar o cliente numa máquina: no painel, \"Instalar em Nova Máquina\"."
    echo "    Para atualizar o painel no futuro, rode este mesmo comando de novo."
}

main "$@"
