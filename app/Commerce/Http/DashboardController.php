<?php

namespace App\Commerce\Http;

use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use Illuminate\View\View;

class DashboardController
{
    public function index(): View
    {
        $organization = ExecutionContext::current()->organization;

        return view('commerce.dashboard', [
            'teamCount' => Membership::active()->where('organization_id', $organization->getKey())->count(),
        ]);
    }
}
