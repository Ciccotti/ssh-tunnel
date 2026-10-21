<?php

namespace App\Policies;

use App\Models\EnrollmentToken;
use App\Models\User;

class EnrollmentTokenPolicy
{
    // Só quem gerou o comando de instalação acompanha o seu andamento
    public function manage(User $user, EnrollmentToken $enrollmentToken): bool
    {
        return (int) $enrollmentToken->user_id === (int) $user->id;
    }
}
