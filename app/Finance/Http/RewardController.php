<?php

namespace App\Finance\Http;

use App\Finance\Access;
use App\Finance\Rewards\Challenge;
use App\Finance\Rewards\Reward;
use App\Finance\Rewards\Rewards;
use App\Finance\Transactions\TransactionFilters;
use App\Platform\Audit\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Conquistas da carteira: nível da raposa, pontos, desafios do mês (sugeridos pelos números da própria carteira)
 * e as conquistas ganhas e as que faltam.
 */
class RewardController
{
    public function __construct(private Rewards $rewards) {}

    public function index(Request $request): View
    {
        $this->rewards->catchUp();
        $month = today()->startOfMonth();
        $active = Challenge::query()->where('month', $month->format('Y-m'))->orderBy('created_at')->get();

        return view('finance.rewards', [
            'level' => $this->rewards->level(),
            'streak' => $this->rewards->positiveStreak(),
            'monthName' => TransactionFilters::monthName($month),
            'challenges' => $active->map(fn (Challenge $c) => ['challenge' => $c, 'progress' => $this->rewards->progress($c)]),
            'suggestions' => $this->rewards->suggestions(),
            'canAccept' => Access::allows($request->attributes->get('finance.membership'), 'finance.transactions.manage')
                && $active->where('status', Challenge::ACTIVE)->count() < Rewards::MAX_ACTIVE,
            'badges' => $this->rewards->badgeList(),
            'history' => Reward::query()->whereIn('kind', [Reward::POINTS, Reward::BADGE])->latest()->limit(15)->get(),
            'ranking' => $this->rewards->ranking($request->attributes->get('finance.membership')->organization_id),
            'pastChallenges' => Challenge::query()->where('month', '<', $month->format('Y-m'))->latest()->limit(6)->get(),
        ]);
    }

    /** Aceita um dos desafios sugeridos (o índice da lista de agora: os números saem da carteira, não do formulário). */
    public function accept(Request $request): RedirectResponse
    {
        $suggestions = $this->rewards->suggestions();
        $data = $request->validate(['suggestion' => ['required', 'integer', Rule::in($suggestions->keys()->all())]], [], ['suggestion' => 'desafio']);
        $challenge = $this->rewards->accept($suggestions[$data['suggestion']], $request->user()->getKey());
        if (! $challenge) {
            return back()->withErrors(['suggestion' => 'Já tem '.Rewards::MAX_ACTIVE.' desafios neste mês. Termine ou desista de um antes.']);
        }
        Audit::record('finance.challenge.accepted', subject: $challenge);

        return back()->with('status', '🎯 Desafio aceito: '.$challenge->label.'. Boa sorte!');
    }

    public function giveUp(Request $request, Challenge $challenge): RedirectResponse
    {
        abort_unless($challenge->status === Challenge::ACTIVE, 404);
        $challenge->delete();
        Audit::record('finance.challenge.dropped', subject: $challenge);

        return back()->with('status', 'Desafio retirado. Dá para escolher outro.');
    }
}
