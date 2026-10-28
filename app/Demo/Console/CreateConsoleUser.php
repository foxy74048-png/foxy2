<?php

namespace App\Demo\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateConsoleUser extends Command
{
    protected $signature = 'foxy:console-user:create {email} {--name=Equipe Foxy}';

    protected $description = 'Cria (ou redefine a senha de) um acesso da equipe Foxy ao console de testes';

    public function handle(): int
    {
        $password = Str::password(16, symbols: false);

        User::updateOrCreate(
            ['email' => $this->argument('email')],
            ['name' => $this->option('name'), 'password' => $password],
        );

        $this->info('Acesso ao console criado. Guarde a senha agora — ela não será mostrada de novo:');
        $this->line($password);

        return self::SUCCESS;
    }
}
