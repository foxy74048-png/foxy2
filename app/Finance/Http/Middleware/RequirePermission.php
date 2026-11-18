<?php

namespace App\Finance\Http\Middleware;

use App\Finance\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `finance.can:finance.people.manage` — o papel da pessoa nesta carteira permite a ação? (depois de ResolveWallet)
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless(Access::allows($request->attributes->get('finance.membership'), $permission), 403, 'O seu papel nesta carteira não permite isso.');

        return $next($request);
    }
}
