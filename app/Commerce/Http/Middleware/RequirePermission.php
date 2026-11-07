<?php

namespace App\Commerce\Http\Middleware;

use App\Commerce\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `commerce.can:commerce.team.manage` — o papel da pessoa nesta loja permite a ação? (depois de ResolveStore)
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless(Access::allows($request->attributes->get('commerce.membership'), $permission), 403, 'O seu papel nesta loja não permite isso.');

        return $next($request);
    }
}
