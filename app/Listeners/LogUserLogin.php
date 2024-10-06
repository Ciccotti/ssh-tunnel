<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Auth;

class LogUserLogin
{
    /**
     * Handle the event.
     *
     * @param  \Illuminate\Auth\Events\Login  $event
     * @return void
     */
    public function handle(Login $event)
    {
        // Captura o IP externo
        $externalIp = request()->ip();

        // Atualiza o campo external_ip do usuário
        $user = $event->user;
        $user->external_ip = $externalIp;
        $user->save();
    }
}

