<?php

namespace App\Commerce\Http;

use App\Commerce\Inventory\StockLevel;
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
            // Peças (não kits) negativas ou no mínimo; o detalhe fica na tela de estoque.
            'lowStock' => StockLevel::whereHas('variant', fn ($q) => $q->where('status', 'active'))
                ->where(fn ($q) => $q->where('on_hand', '<', 0)->orWhereRaw('min_stock is not null and on_hand - reserved <= min_stock'))
                ->count(),
        ]);
    }
}
