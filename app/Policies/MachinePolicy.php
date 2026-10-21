<?php

namespace App\Policies;

use App\Models\Machine;
use App\Models\User;

class MachinePolicy
{
    // Só o dono do cliente ao qual a máquina pertence pode gerenciá-la
    // (excluir, abrir túnel, consultar status)
    public function manage(User $user, Machine $machine): bool
    {
        return $machine->client !== null
            && (int) $machine->client->user_id === (int) $user->id;
    }
}
