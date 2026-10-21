<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
     * Solicitações que ainda ocupam uma porta e uma regra no firewall.
     */
    public function scopeActive(Builder $query)
    {
        $query->whereIn($this->qualifyColumn('status'), ['pending', 'in_progress']);
    }

    /**
     * Solicitações 'pending' dentro do prazo, que a máquina cliente ainda pode atender.
     */
    public function scopeWaitingForClient(Builder $query)
    {
        $query->where($this->qualifyColumn('status'), 'pending')
            ->where($this->qualifyColumn('created_at'), '>', now()->subSeconds(config('tunnel.pending_ttl')));
    }

    /**
     * Indica se a solicitação 'pending' venceu e já pode ser encerrada pelo monitor.
     */
    public function isExpired()
    {
        $limit = config('tunnel.pending_ttl') + config('tunnel.pending_grace');

        return $this->status === 'pending' && $this->created_at->lte(now()->subSeconds($limit));
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

