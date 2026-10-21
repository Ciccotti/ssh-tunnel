<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Endereço público do servidor de túneis
    |--------------------------------------------------------------------------
    |
    | IP ou domínio exibido no painel em "Endereço de Conexão". Se não for
    | definido, usa o host do APP_URL.
    |
    */

    'server_host' => env('TUNNEL_SERVER_HOST') ?: parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST),

    /*
    |--------------------------------------------------------------------------
    | Faixa de portas dos túneis
    |--------------------------------------------------------------------------
    |
    | Portas do servidor sorteadas para o "ssh -R" das máquinas clientes.
    |
    */

    'min_port' => 40000,

    'max_port' => 60000,

    /*
    |--------------------------------------------------------------------------
    | Prazo das solicitações pendentes
    |--------------------------------------------------------------------------
    |
    | Tempo, em segundos, em que uma solicitação 'pending' fica disponível para
    | a máquina cliente. Depois disso a API deixa de entregá-la e o monitor a
    | marca como 'failed' e remove a regra do firewall. A folga (pending_grace)
    | dá tempo para um cliente que pegou a solicitação no fim do prazo terminar
    | de subir o túnel antes de o monitor removê-la.
    |
    */

    'pending_ttl' => (int) env('TUNNEL_PENDING_TTL', 300),

    'pending_grace' => 60,

    /*
    |--------------------------------------------------------------------------
    | Acesso SSH das máquinas clientes
    |--------------------------------------------------------------------------
    |
    | Usuário e porta do sshd usados pelo "ssh -R" das máquinas instaladas pelo
    | painel. As chaves dessas máquinas são entregues ao sshd pelo comando
    | "php artisan tunnel:authorized-keys" (AuthorizedKeysCommand).
    |
    | ssh_host_keys: chaves públicas do sshd, enviadas ao cliente na instalação
    | para ele conferir o servidor (known_hosts) já na primeira conexão.
    |
    */

    'ssh_user' => env('TUNNEL_SSH_USER', 'tunnel'),

    'ssh_port' => (int) env('TUNNEL_SSH_PORT', 22),

    'ssh_host_keys' => env('TUNNEL_SSH_HOST_KEYS', '/etc/ssh/ssh_host_*_key.pub'),

    /*
    |--------------------------------------------------------------------------
    | Validade do comando de instalação
    |--------------------------------------------------------------------------
    |
    | Tempo, em segundos, em que o comando gerado em "Instalar em Nova Máquina"
    | pode ser usado. Cada comando vale para uma única máquina.
    |
    */

    'enrollment_ttl' => (int) env('TUNNEL_ENROLLMENT_TTL', 1800),

];
