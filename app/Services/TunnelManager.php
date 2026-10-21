<?php

namespace App\Services;

use App\Models\ConnectionRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Comandos de sistema usados no ciclo de vida de um túnel: a regra do ufw que
 * libera a porta para o IP do usuário e o processo do sshd que escuta a porta
 * do túnel reverso.
 *
 * Os comandos rodam com o facade Process do Laravel e argumentos em array (sem
 * shell). Falhas lançam Illuminate\Process\Exceptions\ProcessFailedException.
 */
class TunnelManager
{
    // Libera a porta no firewall apenas para o IP do usuário
    public function openFirewallPort($port, $ipAddress)
    {
        $port = $this->validPort($port);
        $ipAddress = $this->validIp($ipAddress);

        Process::run(['sudo', 'ufw', 'allow', 'from', $ipAddress, 'to', 'any', 'port', (string) $port])->throw();

        Log::info("Porta {$port} aberta no firewall para o IP {$ipAddress}.");
    }

    // Remove a regra criada por openFirewallPort()
    public function closeFirewallPort($port, $ipAddress)
    {
        $port = $this->validPort($port);
        $ipAddress = $this->validIp($ipAddress);

        Process::run(['sudo', 'ufw', 'delete', 'allow', 'from', $ipAddress, 'to', 'any', 'port', (string) $port])->throw();

        Log::info("Regra da porta {$port} para o IP {$ipAddress} removida do firewall.");
    }

    // Encerra o processo que escuta a porta do túnel (o sshd do ssh -R do cliente)
    public function terminateListener($port)
    {
        $port = $this->validPort($port);

        // Só processos em LISTEN na porta: a faixa 40000-60000 coincide com as
        // portas efêmeras do Linux, e "-i :PORTA" também pegaria conexões de
        // saída de outros programas que estejam usando essa porta local.
        $result = Process::run(['sudo', 'lsof', '-t', '-sTCP:LISTEN', '-iTCP:' . $port]);

        // lsof sai com código 1 quando não encontra nada. Mantém apenas PIDs numéricos.
        $pids = array_filter(preg_split('/\s+/', trim($result->output())), fn ($pid) => ctype_digit($pid));

        foreach ($pids as $pid) {
            Process::run(['sudo', 'kill', $pid])->throw();
            Log::info("Processo {$pid} na porta {$port} foi encerrado.");
        }
    }

    // Verifica se há algo escutando a porta (o túnel já subiu)
    public function isListening($port)
    {
        $port = (int) $port;
        if ($port < 1 || $port > 65535) {
            return false;
        }

        // Conexão TCP direta com timeout curto, sem depender de lsof/sudo.
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);

        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }

        return false;
    }

    // Encerra o túnel (ou a solicitação ainda pendente) e grava o status final
    public function close(ConnectionRequest $connectionRequest, $status = 'completed')
    {
        $this->terminateListener($connectionRequest->server_port);
        $this->closeFirewallPort($connectionRequest->server_port, $connectionRequest->ip_address);

        $connectionRequest->update(['status' => $status]);

        Log::info("Solicitação {$connectionRequest->id} (porta {$connectionRequest->server_port}) encerrada como '{$status}'.");
    }

    // Uma rodada do monitor (scripts/monitor_tunnels.php)
    public function monitor()
    {
        foreach (ConnectionRequest::active()->get() as $tunnel) {
            try {
                if ($this->isListening($tunnel->server_port)) {
                    if ($tunnel->status === 'pending') {
                        $tunnel->update(['status' => 'in_progress']);
                        Log::info("Túnel na porta {$tunnel->server_port} estabelecido ('in_progress').");
                    }
                } elseif ($tunnel->status === 'in_progress') {
                    // O cliente desconectou
                    $this->close($tunnel, 'completed');
                } elseif ($tunnel->isExpired()) {
                    // A máquina cliente não atendeu a solicitação dentro do prazo
                    $this->close($tunnel, 'failed');
                }
            } catch (\Exception $e) {
                Log::error("Erro ao verificar o túnel na porta {$tunnel->server_port}: " . $e->getMessage());
            }
        }
    }

    // Validação defensiva antes de repassar porta e IP aos comandos
    private function validPort($port)
    {
        $port = (int) $port;
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("Porta inválida: {$port}");
        }

        return $port;
    }

    private function validIp($ipAddress)
    {
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException("Endereço IP inválido: {$ipAddress}");
        }

        return $ipAddress;
    }
}
