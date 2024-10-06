<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class ConnectionRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'machine_id',
        'user_id',
        'service_port',
        'server_port',
        'ip_address',
        'status',
        'requested_at',
        'completed_at'
    ];

    /**
     * Relacionamento: Uma solicitação de conexão pertence a uma máquina.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * Relacionamento: Uma solicitação de conexão pertence a um usuário.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relacionamento: Uma solicitação de conexão tem uma sessão SSH.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function sshSession()
    {
        return $this->hasOne(SshSession::class);
    }
    
    public function setRequestedAtAttribute($value)
    {
        $this->attributes['requested_at'] = Carbon::parse($value);
    }
    
    public function getRequestedAtAttribute($value)
    {
        return Carbon::parse($value);
    }
}

