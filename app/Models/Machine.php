<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class Machine extends Model
{
    use HasFactory;

    /**
     * Os atributos que são atribuíveis em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'hardware_id',
        'name',
        'specifications',
    ];

    /**
     * Relacionamento: Uma máquina pertence a um cliente.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Relacionamento: Uma máquina possui muitas solicitações de conexão.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function connectionRequests()
    {
        return $this->hasMany(ConnectionRequest::class);
    }

    /**
     * Relacionamento: Uma máquina possui uma chave pública.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function machineKey()
    {
        return $this->hasOne(MachineKey::class);
    }

    /**
     * Função para gerar uma porta aleatória dentro de um range e verificar se está disponível.
     *
     * @return int|null
     */
    public function getRandomAvailablePort()
    {
        $minPort = 40000;
        $maxPort = 60000;
        $attempts = 10; // Número de tentativas para encontrar uma porta disponível

        for ($i = 0; $i < $attempts; $i++) {
            // random_int usa CSPRNG, evitando previsibilidade na escolha de portas.
            $randomPort = random_int($minPort, $maxPort);

            // Verifica se a porta está disponível no sistema operacional e não existe nas requisições de conexão ativas
            if ($this->isPortAvailable($randomPort) && !$this->isPortInUse($randomPort)) {
                return $randomPort;
            }
        }

        // Retorna null se não conseguir encontrar uma porta disponível
        return null;
    }

    /**
     * Verifica se a porta está disponível no sistema operacional.
     *
     * @param int $port
     * @return bool
     */
    protected function isPortAvailable($port)
    {
        $port = (int) $port;
        if ($port < 1 || $port > 65535) {
            return false;
        }

        // Usa Symfony Process com argumentos em array para evitar injeção de shell.
        $process = new Process(['ss', '-tuln']);
        $process->run();

        if (!$process->isSuccessful()) {
            // Fallback para netstat com Process sem shell.
            $process = new Process(['netstat', '-tuln']);
            $process->run();
            if (!$process->isSuccessful()) {
                return false;
            }
        }

        // Procura pela porta na saída sem usar shell/grep.
        $needle = ':' . $port;
        foreach (preg_split('/\r?\n/', $process->getOutput()) as $line) {
            if (str_contains($line, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verifica se a porta já está em uso em uma conexão ativa.
     *
     * @param int $port
     * @return bool
     */
    protected function isPortInUse($port)
    {
        return DB::table('connection_requests')
            ->where('server_port', $port)
            ->whereIn('status', ['pending', 'in_progress'])
            ->exists();
    }

    /**
     * Função para abrir a porta no firewall (somente para o IP do usuário).
     *
     * @param string $ipAddress
     * @param int $port
     * @return void
     */
    public function openPortInFirewall($ipAddress, $port)
    {
        $port = (int) $port;
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("Porta inválida: {$port}");
        }
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException("Endereço IP inválido: {$ipAddress}");
        }

        // Comando para abrir a porta no firewall para um IP específico (ufw)
        // usando argumentos em array para evitar injeção de shell.
        $process = new Process(['ufw', 'allow', 'from', $ipAddress, 'to', 'any', 'port', (string) $port]);
        $process->run();
    }

    /**
     * Função para fechar a porta no firewall.
     *
     * @param int $port
     * @return void
     */
    public function closePortInFirewall($port)
    {
        $port = (int) $port;
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("Porta inválida: {$port}");
        }

        // Comando para fechar a porta no firewall usando argumentos em array.
        $process = new Process(['ufw', 'delete', 'allow', (string) $port]);
        $process->run();
    }
}
