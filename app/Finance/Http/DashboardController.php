<?php

namespace App\Finance\Http;

use App\Finance\Access;
use App\Platform\Memberships\Membership;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Início da carteira. Na fatia 1 mostra a carteira, quem está nela e os próximos passos; os painéis de
 * entradas, saídas e categorias chegam com os lançamentos (fatias 2 e 6).
 */
class DashboardController
{
    public function index(Request $request): View
    {
        $membership = $request->attributes->get('finance.membership');

        return view('finance.dashboard', [
            'people' => Membership::active()->where('organization_id', $membership->organization_id)->count(),
            'canManagePeople' => Access::allows($membership, 'finance.people.manage'),
        ]);
    }
}
