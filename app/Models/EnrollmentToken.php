<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Código de uso único do comando de instalação do cliente. O código em texto
 * só existe no comando mostrado ao usuário; o banco guarda o hash.
 */
class EnrollmentToken extends Model
{
    protected $fillable = [
        'user_id',
        'client_id',
        'token_hash',
        'expires_at',
        'used_at',
        'machine_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * Cria um código para instalar uma máquina no cliente informado.
     *
     * @return array{0: self, 1: string} o registro e o código em texto
     */
    public static function issue(Client $client): array
    {
        $plainToken = Str::random(32);

        $token = static::create([
            'user_id' => $client->user_id,
            'client_id' => $client->id,
            'token_hash' => static::hash($plainToken),
            'expires_at' => now()->addSeconds(config('tunnel.enrollment_ttl')),
        ]);

        return [$token, $plainToken];
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Códigos ainda não usados e dentro da validade.
     */
    public function scopeUsable(Builder $query)
    {
        $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    public static function findUsable(string $plainToken): ?self
    {
        return static::usable()->where('token_hash', static::hash($plainToken))->first();
    }
}
