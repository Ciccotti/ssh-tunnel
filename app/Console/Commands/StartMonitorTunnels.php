<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\MonitorTunnelsJob;

class StartMonitorTunnels extends Command
{
    protected $signature = 'tunnels:monitor';
    protected $description = 'Inicia o monitoramento contínuo dos túneis.';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        // Dispara o job de monitoramento contínuo
        dispatch(new MonitorTunnelsJob());

        $this->info('Monitoramento dos túneis iniciado.');
    }
}

