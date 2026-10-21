<?php

namespace App\Console\Commands;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cria um usuário do painel pela linha de comando (usado pelo install.sh para
 * o primeiro acesso). Sem --password, gera uma senha aleatória e a mostra.
 */
class CreateUser extends Command
{
    protected $signature = 'user:create
        {email : e-mail de login}
        {--name= : nome exibido no painel (padrão: o início do e-mail)}
        {--password= : senha (padrão: gera uma aleatória)}';

    protected $description = 'Cria um usuário do painel';

    public function handle(CreateNewUser $creator): int
    {
        $email = $this->argument('email');
        $password = $this->option('password') ?: Str::password(16, symbols: false);

        try {
            $user = $creator->create([
                'name' => $this->option('name') ?: Str::before($email, '@'),
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
                'terms' => true,
            ]);
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info("Usuário {$user->email} criado.");
        if (!$this->option('password')) {
            $this->line("Senha: {$password}");
        }

        return self::SUCCESS;
    }
}
