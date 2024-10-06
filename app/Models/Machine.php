<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
            $randomPort = rand($minPort, $maxPort);

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
        // Usando shell_exec para verificar portas em uso no sistema (Linux)
        $result = shell_exec("netstat -tuln | grep :$port");

        return empty($result); // Se não houver resultado, a porta está disponível
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
        // Comando para abrir a porta no firewall para um IP específico (exemplo com ufw)
        shell_exec("ufw allow from $ipAddress to any port $port");
    }

    /**
     * Função para fechar a porta no firewall.
     *
     * @param int $port
     * @return void
     */
    public function closePortInFirewall($port)
    {
        // Comando para fechar a porta no firewall
        shell_exec("ufw delete allow $port");
    }
}
