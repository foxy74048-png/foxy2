<?php

namespace App\Platform\Identity;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console e Foxy Admin: só a equipe Foxy. Clientes dos produtos também têm login no Core e não entram aqui.
 */
class RequireStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isStaff(), 403, 'Acesso só da equipe Foxy.');

        return $next($request);
    }
}
