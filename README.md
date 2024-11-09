
# Solução de Acesso Remoto a Redes Privadas Utilizando Túnel SSH Reverso

Este repositório apresenta uma solução de TCC desenvolvida na **Universidade Federal de Uberlândia (UFU)** para acesso remoto seguro a redes privadas, utilizando túneis SSH reversos. Este projeto, de autoria de **Matheus Lopes Ciccotti** sob a orientação do Dr. Diego Nunes Molinos, visa resolver desafios de segurança e acessibilidade em redes corporativas com restrições de firewall e NAT.

## Visão Geral do Projeto

O projeto implementa uma aplicação web para gerenciamento de túneis SSH reversos, permitindo que usuários autenticados possam abrir e fechar conexões seguras com máquinas clientes (Windows ou Linux), mesmo em redes protegidas. A aplicação foi desenvolvida em Laravel, e o script do cliente em Python consulta um endpoint para verificar solicitações de conexão pendentes, estabelecendo o túnel reverso para a interface web.

## Estrutura do Repositório

- **scripts/script_cliente.py**: Script Python para execução nas máquinas clientes, que monitora o servidor em busca de solicitações de túnel e, caso existam, estabelece o túnel SSH reverso.
- **requirements-windows.txt**: Lista de dependências para o script cliente em sistemas Windows.
- **requirements-linux.txt**: Lista de dependências para o script cliente em sistemas Linux.

## Instalação das Dependências

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

### 2. Executar o Script Cliente
   Após configurar as dependências e a chave privada, execute o script cliente:

   ```bash
   python script_cliente.py
   ```

### 3. Cadastro do Hardware ID
   Durante a primeira execução do script cliente, será gerado um **Hardware ID** único para a máquina. Este identificador deve ser **cadastrado na dashboard da interface web** para que o servidor possa autenticar a máquina e permitir o estabelecimento dos túneis SSH. Este passo é essencial para garantir que apenas máquinas autorizadas acessem a rede.

### 4. Gerenciamento via Interface Web
   Na aplicação web, usuários podem gerenciar conexões e verificar o status dos túneis. A interface permite configurar portas de redirecionamento, visualizar logs e monitorar os túneis ativos, garantindo controle e segurança.

## Objetivo do Projeto

Este projeto oferece uma alternativa para profissionais de TI que buscam um método seguro e eficaz de acesso remoto, evitando as dificuldades de configuração de rede em ambientes restritos. As soluções propostas incluem autenticação segura, uso de hardware IDs únicos, e implementação de túneis reversos para manter a integridade dos dados.

## Palavras-chave

**Acesso remoto**, **SSH**, **Aplicação web**, **Segurança da informação**, **Túnel reverso**, **Firewall**.

### Este projeto utiliza as seguintes versões de software:

- **Laravel Framework**: 11.24.1
- **PHP**: 8.3.6
- **Zend Engine**: v4.3.6
- **MySQL**: 8.0.39
- **Nginx**: 1.24.0
- **Python**: 3.12.6
