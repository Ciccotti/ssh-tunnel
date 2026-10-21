
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

## Estrutura do Repositório

- **scripts/script_cliente.py**: Script Python para execução nas máquinas clientes, que monitora o servidor em busca de solicitações de túnel e, caso existam, estabelece o túnel SSH reverso.
- **scripts/requirements-windows.txt**: Lista de dependências para o script cliente em sistemas Windows.
- **scripts/requirements-linux.txt**: Lista de dependências para o script cliente em sistemas Linux.
- **scripts/monitor_tunnels.php**: Monitor que roda no servidor e verifica os túneis a cada 2 segundos (veja [Ciclo de Vida de um Túnel](#ciclo-de-vida-de-um-túnel)).
- **app/Services/TunnelManager.php**: Comandos de sistema usados pelo painel e pelo monitor para abrir e fechar túneis (regra do `ufw` e processo do túnel).
- **config/tunnel.php**: Configurações dos túneis: endereço do servidor, prazo das solicitações pendentes e faixa de portas.
- **documentacao/supervisor**: Instalação e configuração do Supervisor, que mantém o monitor rodando.

## Ciclo de Vida de um Túnel

1. **Abertura**: no painel, o usuário informa a porta do serviço na máquina cliente (ex.: 3389 para RDP) e clica em **Abrir Túnel**. O servidor sorteia uma porta livre entre 40000 e 60000, que não esteja em uso no sistema nem reservada por outra solicitação ativa, e libera essa porta no `ufw` somente para o IP de quem pediu. A solicitação é criada com status `pending`.
2. **Conexão**: o script cliente consulta o servidor a cada 5 segundos com o seu Hardware ID. Ao receber a solicitação pendente, executa o `ssh -R` para a porta sorteada.
3. **Túnel ativo**: o monitor percebe que a porta passou a escutar e muda o status para `in_progress`. O painel mostra o **Endereço de Conexão** (`servidor:porta`) e o botão **Fechar Túnel**.
4. **Fechamento**: pelo botão **Fechar Túnel** ou automaticamente, quando o cliente desconecta. O servidor encerra o processo que escuta a porta do túnel, remove a regra do `ufw` e marca a solicitação como `completed`.
5. **Prazo da solicitação pendente**: se a máquina cliente não atender a solicitação em `TUNNEL_PENDING_TTL` segundos (padrão: 5 minutos), o servidor deixa de entregá-la ao cliente. O monitor então a marca como `failed` e remove a regra do `ufw`, com uma folga de 60 segundos para um cliente que a recebeu no fim do prazo terminar de conectar. Isso evita que, horas depois, a porta seja aberta para um IP que já pertence a outra pessoa. Enquanto aguarda, o usuário pode desistir pelo botão **Cancelar**.
6. **Exclusão**: ao excluir uma máquina ou um cliente, os túneis ativos e pendentes são fechados antes. Se não for possível fechá-los, a exclusão não é feita.

## Configuração do Servidor

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
   | `TUNNEL_SERVER_HOST` | host do `APP_URL` | IP ou domínio público do servidor, exibido no painel em **Endereço de Conexão**. |
   | `TUNNEL_PENDING_TTL` | `300` | Tempo, em segundos, em que uma solicitação pendente fica disponível para a máquina cliente. |

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

   No SSH, o usuário usado pelas máquinas clientes precisa ter as chaves públicas delas no `~/.ssh/authorized_keys`, e o `sshd` precisa de `GatewayPorts yes` para que a porta do túnel aceite conexões vindas de fora do servidor.

### 4. Monitor de Túneis

   O `scripts/monitor_tunnels.php` precisa ficar rodando o tempo todo. A instalação com o Supervisor está em `documentacao/supervisor`. Sem o monitor, os túneis não aparecem como conectados no painel e as solicitações vencidas não são encerradas.

### 5. Testes

   Com o `.env` criado (`APP_KEY` gerada) e os assets compilados (`npm run build`):

   ```bash
   php artisan test
   ```

   Os testes usam SQLite em memória e simulam os comandos de sistema (`sudo`, `ufw`, `lsof`, `kill`), sem alterar o firewall nem processos da máquina.

## Instalação das Dependências do Script Cliente

Na pasta `scripts`:

1. **Para Windows**: Execute o seguinte comando no terminal:
   ```bash
   pip install -r requirements-windows.txt
   ```

2. **Para Linux**: Execute o seguinte comando no terminal:
   ```bash
   pip install -r requirements-linux.txt
   ```

## Configuração e Execução

### 1. Gerar e Configurar a Chave Privada
   - Gere uma chave privada SSH para a autenticação segura e coloque-a no diretório do script cliente (`scripts/script_cliente.py`).
   - Ao iniciar o script, as permissões da chave privada serão ajustadas automaticamente para garantir segurança.
   - No início do `script_cliente.py`, preencha `CHECK_TUNNEL_ENDPOINT` (URL da API do painel), `SERVER_ADDRESS`, `USERNAME_SSH` e `PRIVATE_KEY_PATH` (nome do arquivo da chave).
   - Recomendado: coloque ao lado do script um arquivo `known_hosts` com a chave pública do servidor (a saída de `ssh-keyscan <servidor>`). Sem ele, a chave do servidor é aceita na primeira conexão e verificada nas seguintes.

### 2. Executar o Script Cliente
   Após configurar as dependências e a chave privada, execute o script cliente:

   ```bash
   python script_cliente.py
   ```

   O túnel é aberto com `ExitOnForwardFailure=yes`, para que o `ssh` termine se o servidor recusar a porta em vez de ficar conectado sem túnel, e com `ServerAliveInterval=30`, para manter a conexão ativa atrás de NAT/firewall e perceber quando ela cai.

### 3. Cadastro do Hardware ID
   Durante a primeira execução do script cliente, será gerado um **Hardware ID** único para a máquina. Este identificador deve ser **cadastrado na dashboard da interface web** para que o servidor possa autenticar a máquina e permitir o estabelecimento dos túneis SSH. Este passo é essencial para garantir que apenas máquinas autorizadas acessem a rede.

### 4. Gerenciamento via Interface Web
   Na aplicação web, usuários podem gerenciar conexões e verificar o status dos túneis. A interface permite configurar portas de redirecionamento, visualizar logs e monitorar os túneis ativos, garantindo controle e segurança.

   Cada usuário vê e gerencia apenas os próprios clientes e máquinas. Para cada máquina, o painel mostra um botão conforme o estado do túnel:

   - **Abrir Túnel**: cria a solicitação de conexão;
   - **Cancelar**: desiste da solicitação enquanto a máquina cliente ainda não conectou;
   - **Fechar Túnel**: encerra o túnel ativo.

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
