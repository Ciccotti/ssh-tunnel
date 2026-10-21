<?php

namespace App\Console\Commands;

use App\Models\ConnectionRequest;
use App\Models\MachineKey;
use App\Support\SshPublicKey;
use Illuminate\Console\Command;

/**
 * Entrega ao sshd as chaves das máquinas instaladas pelo painel. Configurado no
 * sshd_config como AuthorizedKeysCommand do usuário dos túneis.
 *
 * Cada chave só pode fazer "ssh -R" nas portas das solicitações ativas da
 * própria máquina (permitlisten): uma máquina não consegue ocupar a porta
 * reservada para outra. Sem solicitação ativa, a chave autentica mas não abre
 * túnel. command="/bin/true" impede o uso como shell.
 */
class TunnelAuthorizedKeys extends Command
{
    protected $signature = 'tunnel:authorized-keys {user : usuário que está entrando (o %u do sshd)}';

    protected $description = 'Imprime as chaves das máquinas clientes no formato authorized_keys (AuthorizedKeysCommand do sshd)';

    public function handle(): int
    {
        if ($this->argument('user') !== config('tunnel.ssh_user')) {
            return self::SUCCESS;
        }

        $portsByMachine = ConnectionRequest::active()
            ->get(['machine_id', 'server_port'])
            ->groupBy('machine_id');

        foreach (MachineKey::orderBy('id')->get() as $key) {
            // Só chaves válidas: uma linha com opções ou quebra de linha não chega ao sshd
            $publicKey = SshPublicKey::normalize($key->public_key);
            if (!$publicKey) {
                continue;
            }

            $ports = $portsByMachine->get($key->machine_id, collect())->pluck('server_port');

            $this->line(self::authorizedKeysLine($publicKey, $ports->all(), 'machine-'.$key->machine_id));
        }

        return self::SUCCESS;
    }

    /**
     * Monta uma linha do authorized_keys com as restrições do túnel.
     *
     * @param  array<int|string>  $ports
     */
    public static function authorizedKeysLine(string $publicKey, array $ports, string $comment): string
    {
        $options = ['restrict'];

        $ports = array_unique(array_filter(array_map('intval', $ports), fn ($port) => $port >= 1 && $port <= 65535));
        if ($ports) {
            $options[] = 'port-forwarding';
            foreach ($ports as $port) {
                $options[] = 'permitlisten="'.$port.'"';
            }
        }

        $options[] = 'command="/bin/true"';

        return implode(',', $options).' '.$publicKey.' '.$comment;
    }
}
