<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}

