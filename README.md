
# Solução de Acesso Remoto a Redes Privadas Utilizando Túnel SSH Reverso

Este repositório apresenta uma solução de TCC desenvolvida na **Universidade Federal de Uberlândia (UFU)** para acesso remoto seguro a redes privadas, utilizando túneis SSH reversos.

## Visão Geral do Projeto

O projeto implementa uma aplicação web para gerenciamento de túneis SSH reversos, permitindo que usuários autenticados possam abrir e fechar conexões seguras com máquinas clientes (Windows ou Linux), mesmo em redes protegidas. A aplicação foi desenvolvida em Laravel, e o script do cliente em Python consulta um endpoint para verificar solicitações de conexão pendentes, estabelecendo o túnel reverso para a interface web.

### Ilustração da Solução:

                                 +----------------+
                                 |     Usuário    |
                                 |                |
                                 +----------------+
                                         |
                                         |
                                Acesso via web (site)
                                         |
                                         |
                                         v
                           +---------------------------+
                           |        Servidor Web       |
                           |                           |
                           | (Painel de gerenciamento) |
                           |                           |
                           | Linux, Laravel, PHP,      |
                           | Nginx e SSH               |
                           +---------------------------+
                              ^                 ^
                              |                 |
                              |                 |
                              |                 |
                              Túneis SSH Reversos
                              |                 |
                              |                 |
                              |                 |
                              |                 |
                  +-------------------+   +-------------------+
                  |    Cliente 1      |   |     Cliente 2     |
                  |                   |   |                   |
                  |(Atrás de firewall)|   |(Atrás de firewall)|
                  |                   |   |                   |
                  |    Rodando RDP    |   |  Rodando Cockpit  |
                  |   na porta 3389   |   |   na porta 9090   |
                  +-------------------+   +-------------------+

## Instalação Rápida

### Servidor (painel e túneis)

Num servidor Ubuntu 24.04+ ou Debian 12+ recém-instalado, de preferência com um domínio apontando para ele:

```bash
curl -fsSL https://raw.githubusercontent.com/Ciccotti/ssh-tunnel/master/install.sh | sudo bash
```

O instalador pergunta o domínio (ou o IP) e o seu e-mail, e configura tudo:

- Nginx com HTTPS (Let's Encrypt), PHP, MariaDB e o painel;
- o usuário SSH dos túneis, que só pode fazer `ssh -R`;
- o firewall (`ufw`), as permissões de `sudo` do painel e o monitor de túneis.

No fim, ele mostra o endereço do painel e a senha do primeiro usuário.

Rodar o mesmo comando de novo atualiza o painel, mantendo o `.env`, o banco e o certificado. Para rodar sem perguntas:

```bash
curl -fsSL https://raw.githubusercontent.com/Ciccotti/ssh-tunnel/master/install.sh | sudo DOMINIO=painel.exemplo.com EMAIL=voce@exemplo.com bash
```

As demais opções estão no início do `install.sh`.

> Ao informar o e-mail com HTTPS, você aceita os termos de uso do Let's Encrypt. O instalador ativa o `ufw` liberando só SSH, HTTP e HTTPS: outros serviços expostos no servidor deixam de ser acessíveis de fora.

### Máquinas clientes

No painel, clique em **Instalar em Nova Máquina**, escolha o cliente e copie o comando:

- **Windows** (PowerShell como administrador): `irm https://painel.exemplo.com/instalar/<código>/windows | iex`
- **Linux**: `curl -fsSL https://painel.exemplo.com/instalar/<código>/linux | sudo bash`

O comando instala o cliente, gera a chave SSH da própria máquina, cadastra a máquina no painel e deixa o cliente rodando, inclusive depois de reiniciar a máquina:

- **Windows**: tarefa agendada `ssh-tunnel-client`, rodando como SYSTEM. Log em `C:\ProgramData\ssh-tunnel-client\client.log`.
- **Linux**: serviço `ssh-tunnel-client` do systemd. Log em `journalctl -u ssh-tunnel-client`.

Não é preciso instalar Python nem copiar o Hardware ID. No Windows, o instalador usa um Python portátil oficial do python.org, conferido pelo SHA-256.

Cada comando vale para uma única máquina, por 30 minutos (`TUNNEL_ENROLLMENT_TTL`). Rodar um comando novo numa máquina já instalada reinstala o cliente sem duplicá-la no painel.

Cada máquina tem a sua própria chave SSH. O `sshd` pergunta ao painel quais chaves aceitar (`php artisan tunnel:authorized-keys`), e cada chave só consegue abrir túnel na porta reservada para a sua máquina, sem acesso a shell.

### Desenvolvimento

```bash
composer run setup   # primeira vez: dependências, .env, banco SQLite e build do front
composer run dev     # painel em http://localhost:8000, com o Vite recompilando o front
composer run test    # testes
```

## Estrutura do Repositório

- **install.sh**: Instalador do servidor (veja [Instalação Rápida](#instalação-rápida)).
- **resources/install/**: Instaladores do cliente para Windows (`windows.ps1`) e Linux (`linux.sh`), entregues pelo painel com o código de instalação embutido.
- **scripts/script_cliente.py**: Script Python para execução nas máquinas clientes, que monitora o servidor em busca de solicitações de túnel e, caso existam, estabelece o túnel SSH reverso.
- **scripts/requirements-windows.txt** e **scripts/requirements-linux.txt**: Dependências do script cliente na instalação manual.
- **scripts/monitor_tunnels.php**: Monitor que roda no servidor e verifica os túneis a cada 2 segundos (veja [Ciclo de Vida de um Túnel](#ciclo-de-vida-de-um-túnel)).
- **app/Services/TunnelManager.php**: Comandos de sistema usados pelo painel e pelo monitor para abrir e fechar túneis (regra do `ufw` e processo do túnel).
- **app/Http/Controllers/EnrollmentController.php**: Comandos de instalação gerados no painel e registro das máquinas clientes.
- **config/tunnel.php**: Configurações dos túneis: endereço e SSH do servidor, prazos e faixa de portas.
- **documentacao/supervisor**: Alternativa ao serviço do systemd para manter o monitor rodando, na instalação manual.
- **tests/e2e/**: Testes de ponta a ponta dos instaladores, rodados no GitHub Actions em VMs descartáveis.

## Ciclo de Vida de um Túnel

1. **Abertura**: no painel, o usuário informa a porta do serviço na máquina cliente (ex.: 3389 para RDP) e clica em **Abrir Túnel**. O servidor sorteia uma porta livre entre 40000 e 60000, que não esteja em uso no sistema nem reservada por outra solicitação ativa, e libera essa porta no `ufw` somente para o IP de quem pediu. A solicitação é criada com status `pending`.
2. **Conexão**: o script cliente consulta o servidor a cada 5 segundos com o seu Hardware ID. Ao receber a solicitação pendente, executa o `ssh -R` para a porta sorteada.
3. **Túnel ativo**: o monitor percebe que a porta passou a escutar e muda o status para `in_progress`. O painel mostra o **Endereço de Conexão** (`servidor:porta`) e o botão **Fechar Túnel**.
4. **Fechamento**: pelo botão **Fechar Túnel** ou automaticamente, quando o cliente desconecta. O servidor encerra o processo que escuta a porta do túnel, remove a regra do `ufw` e marca a solicitação como `completed`.
5. **Prazo da solicitação pendente**: se a máquina cliente não atender a solicitação em `TUNNEL_PENDING_TTL` segundos (padrão: 5 minutos), o servidor deixa de entregá-la ao cliente. O monitor então a marca como `failed` e remove a regra do `ufw`, com uma folga de 60 segundos para um cliente que a recebeu no fim do prazo terminar de conectar. Isso evita que, horas depois, a porta seja aberta para um IP que já pertence a outra pessoa. Enquanto aguarda, o usuário pode desistir pelo botão **Cancelar**.
6. **Exclusão**: ao excluir uma máquina ou um cliente, os túneis ativos e pendentes são fechados antes. Se não for possível fechá-los, a exclusão não é feita.

## Gerenciamento via Interface Web

Na aplicação web, usuários podem gerenciar conexões e verificar o status dos túneis. Cada usuário vê e gerencia apenas os próprios clientes e máquinas. Para cada máquina, o painel mostra um botão conforme o estado do túnel:

- **Abrir Túnel**: cria a solicitação de conexão;
- **Cancelar**: desiste da solicitação enquanto a máquina cliente ainda não conectou;
- **Fechar Túnel**: encerra o túnel ativo.

Para criar usuários sem o cadastro pela web: `php artisan user:create email@exemplo.com` (gera e mostra uma senha aleatória).

## Instalação Manual do Servidor

O `install.sh` faz todos os passos abaixo. Eles ficam aqui para quem quer instalar à mão ou entender o que o instalador configura.

### 1. Instalação da Aplicação Web

   ```bash
   composer install
   cp .env.example .env
   php artisan key:generate
   php artisan migrate
   npm install
   npm run build
   ```

### 2. Variáveis de Ambiente dos Túneis

   Defina no `.env`:

   | Variável | Padrão | Descrição |
   |---|---|---|
   | `TUNNEL_SERVER_HOST` | host do `APP_URL` | IP ou domínio público do servidor, exibido no painel em **Endereço de Conexão** e usado pelos clientes no `ssh -R`. |
   | `TUNNEL_PENDING_TTL` | `300` | Tempo, em segundos, em que uma solicitação pendente fica disponível para a máquina cliente. |
   | `TUNNEL_SSH_USER` | `tunnel` | Usuário do servidor que recebe o `ssh -R` das máquinas clientes. |
   | `TUNNEL_SSH_PORT` | `22` | Porta do `sshd`. |
   | `TUNNEL_ENROLLMENT_TTL` | `1800` | Tempo, em segundos, em que o comando de **Instalar em Nova Máquina** pode ser usado. |

### 3. Permissões de Sistema

   O painel e o monitor executam os comandos abaixo com `sudo`, sem senha:

   - `ufw allow from <IP> to any port <porta>` e `ufw delete allow from <IP> to any port <porta>`: liberam e removem a porta do túnel para o IP do usuário;
   - `lsof -t -sTCP:LISTEN -iTCP:<porta>`: encontra o processo que escuta a porta do túnel;
   - `kill <pid>`: encerra esse processo ao fechar o túnel.

   Exemplo de regra (`sudo visudo -f /etc/sudoers.d/ssh-tunnel`), ajustando o usuário do PHP e os caminhos dos programas (`which ufw lsof kill`):

   ```
   www-data ALL=(root) NOPASSWD: /usr/sbin/ufw, /usr/bin/lsof, /usr/bin/kill
   ```

   Essa regra dá ao usuário do PHP controle sobre o firewall e os processos do servidor, por isso não a amplie para outros programas.

### 4. SSH dos Túneis

   Crie o usuário que recebe os túneis (`useradd --system --create-home --shell /bin/sh tunnel && usermod -p '*' tunnel`) e o script `/usr/libexec/ssh-tunnel/authorized-keys`, do root e com permissão `0755` (o `sshd` exige que ele e todas as pastas acima sejam do root e sem escrita para outros), ajustando o caminho do painel:

   ```sh
   #!/bin/sh
   exec /usr/bin/php /var/www/ssh-tunnel/artisan tunnel:authorized-keys "$1" 2>/dev/null
   ```

   E em `/etc/ssh/sshd_config.d/ssh-tunnel.conf`:

   ```
   Match User tunnel
       AuthorizedKeysCommand /usr/libexec/ssh-tunnel/authorized-keys %u
       AuthorizedKeysCommandUser www-data
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
   ```

   O `GatewayPorts yes` faz a porta do túnel aceitar conexões vindas de fora do servidor (o `ufw` só libera para o IP de quem pediu). Confira com `sudo sshd -t` e aplique com `sudo systemctl reload ssh`.

   Clientes da instalação manual continuam funcionando com as chaves no `~/.ssh/authorized_keys` desse usuário, com as mesmas restrições do bloco `Match`.

### 5. Monitor de Túneis

   O `scripts/monitor_tunnels.php` precisa ficar rodando o tempo todo, como o usuário do PHP (`www-data`). O `install.sh` cria o serviço `ssh-tunnel-monitor` do systemd; a alternativa com o Supervisor está em `documentacao/supervisor`. Sem o monitor, os túneis não aparecem como conectados no painel e as solicitações vencidas não são encerradas.

### 6. Testes

   ```bash
   composer run test
   ```

   Os testes usam SQLite em memória e simulam os comandos de sistema (`sudo`, `ufw`, `lsof`, `kill`), sem alterar o firewall nem processos da máquina. Os instaladores são testados de ponta a ponta no GitHub Actions (`.github/workflows/instaladores.yml`), em VMs descartáveis.

## Instalação Manual do Cliente

O comando de **Instalar em Nova Máquina** faz todos os passos abaixo.

### 1. Dependências

   O script usa só a biblioteca padrão do Python 3. No Windows, o modo manual também precisa do pacote `wmi` para calcular o Hardware ID. Na pasta `scripts`:

   ```bash
   pip install -r requirements-windows.txt
   ```

### 2. Gerar e Configurar a Chave Privada
   - Gere uma chave privada SSH para a autenticação segura e coloque-a no diretório do script cliente (`scripts/script_cliente.py`).
   - Ao iniciar o script, as permissões da chave privada serão ajustadas automaticamente para garantir segurança.
   - No início do `script_cliente.py`, preencha `CHECK_TUNNEL_ENDPOINT` (URL da API do painel), `SERVER_ADDRESS`, `USERNAME_SSH` e `PRIVATE_KEY_PATH` (nome do arquivo da chave).
   - Recomendado: coloque ao lado do script um arquivo `known_hosts` com a chave pública do servidor (a saída de `ssh-keyscan <servidor>`). Sem ele, a chave do servidor é aceita na primeira conexão e verificada nas seguintes.

### 3. Executar o Script Cliente

   ```bash
   python script_cliente.py
   ```

   O túnel é aberto com `ExitOnForwardFailure=yes`, para que o `ssh` termine se o servidor recusar a porta em vez de ficar conectado sem túnel, e com `ServerAliveInterval=30`, para manter a conexão ativa atrás de NAT/firewall e perceber quando ela cai.

### 4. Cadastro do Hardware ID
   Durante a primeira execução do script cliente, será gerado um **Hardware ID** único para a máquina. Este identificador deve ser **cadastrado na dashboard da interface web** (**Cadastrar Máquina**) para que o servidor possa autenticar a máquina e permitir o estabelecimento dos túneis SSH.

## Objetivo do Projeto

Este projeto oferece uma alternativa para profissionais de TI que buscam um método seguro e eficaz de acesso remoto, evitando as dificuldades de configuração de rede em ambientes restritos. As soluções propostas incluem autenticação segura, uso de hardware IDs únicos, e implementação de túneis reversos para manter a integridade dos dados.

## Palavras-chave

**Acesso remoto**, **SSH**, **Aplicação web**, **Segurança da informação**, **Túnel reverso**, **Firewall**.

### Este projeto utiliza as seguintes versões de software:

- **Laravel Framework**: 12.70.0
- **PHP**: 8.3.6
- **Zend Engine**: v4.3.6
- **MySQL**: 8.0.39
- **Nginx**: 1.24.0
- **Python**: 3.12.6
