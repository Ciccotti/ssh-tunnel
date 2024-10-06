<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MachineKey extends Model
{
    use HasFactory;

    /**
     * Os atributos que são atribuíveis em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'machine_id',
        'public_key',
    ];

    /**
     * Relacionamento: Uma MachineKey pertence a uma Máquina.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }
}

