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

];
