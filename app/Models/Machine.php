<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

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
        $minPort = config('tunnel.min_port');
        $maxPort = config('tunnel.max_port');
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

        // Usa o Process do Laravel com argumentos em array para evitar injeção de shell.
        $result = Process::run(['ss', '-tuln']);

        if (!$result->successful()) {
            // Fallback para netstat, também sem shell.
            $result = Process::run(['netstat', '-tuln']);
            if (!$result->successful()) {
                return false;
            }
        }

        // Procura pela porta na saída sem usar shell/grep.
        $needle = ':' . $port;
        foreach (preg_split('/\r?\n/', $result->output()) as $line) {
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
}
