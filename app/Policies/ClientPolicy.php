<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    // Só o usuário que cadastrou o cliente pode gerenciá-lo
    public function manage(User $user, Client $client): bool
    {
        return (int) $client->user_id === (int) $user->id;
    }
}
