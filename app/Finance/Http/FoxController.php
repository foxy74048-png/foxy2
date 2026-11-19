<?php

namespace App\Finance\Http;

use App\Finance\Assistant\AiNote;
use App\Finance\Assistant\FinanceFox;
use App\Finance\Insights\Indicators;
use App\Finance\Statements\StatementLine;
use App\Finance\Transactions\TransactionFilters;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * A raposa no Finanças (fatia 8): a janela de conversa (contrato do foxy-assistant.js), "Sugerir com a raposa"
 * no extrato e as dicas do mês. A IA age em nome da pessoa (auditoria com on_behalf_of).
 */
class FoxController
{
    public function __construct(private FinanceFox $fox, private ContextStore $contexts) {}

    public function message(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'page' => ['nullable', 'string', 'max:200'],
            'error' => ['nullable', 'array:message,code,action'],
            'error.message' => ['required_with:error', 'string', 'max:500'],
            'error.code' => ['nullable', 'string', 'max:60'],
            'error.action' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->json(fn () => $this->asAgent($request, fn () => $this->fox->ask(
            ExecutionContext::current()->organization, $request->user(), $request->attributes->get('finance.membership'), $request->attributes->get('finance.wallet'),
            $data['text'], $data['page'] ?? null, $data['error'] ?? null,
        )));
    }

    public function reset(Request $request): JsonResponse
    {
        return $this->json(function () use ($request) {
            $this->fox->forget();
            $conversation = $this->fox->open(ExecutionContext::current()->organization, $request->user(), $request->attributes->get('finance.membership'), $request->attributes->get('finance.wallet'));

            return ['assistant' => $conversation['assistant']];
        });
    }

    public function setup(Request $request): JsonResponse
    {
        $data = $request->validate(['assistant' => ['required', 'string', 'max:30'], 'visual' => ['nullable', 'string', 'max:30'], 'custom_name' => ['nullable', 'string', 'max:30']]);

        return $this->json(fn () => ['assistant' => $this->fox->choose(
            ExecutionContext::current()->organization, $request->user(), $data['assistant'], $data['visual'] ?? null, isset($data['custom_name']) ? trim($data['custom_name']) ?: null : null,
        )]);
    }

    /** "Sugerir com a raposa": linhas do extrato para revisar que ficaram sem categoria (até 50 por vez). */
    public function categorize(Request $request): RedirectResponse
    {
        $lines = StatementLine::query()->where('status', StatementLine::PENDING)->whereNull('hidden_at')->whereNull('suggested_category_id')
            ->orderByDesc('date')->limit(50)->get();
        if ($lines->isEmpty()) {
            return back()->with('status', 'Todas as linhas para revisar já têm uma sugestão de categoria.');
        }

        try {
            $found = $this->asAgent($request, fn () => $this->fox->categorize(ExecutionContext::current()->organization, $lines));
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['fox' => $e instanceof \RuntimeException ? $e->getMessage() : 'A raposa está indisponível agora.']);
        }
        foreach ($found as $lineId => $category) {
            StatementLine::whereKey($lineId)->update(['suggested_category_id' => $category->id, 'suggestion_origin' => 'raposa', 'updated_at' => now()]);
        }

        return back()->with('status', count($found).' de '.$lines->count().' linha(s) ganharam uma sugestão da raposa. Confira ao lançar; marcando "sempre que aparecer", vira regra.');
    }

    /** Dicas da raposa sobre o mês: feitas uma vez e guardadas; "de novo" refaz (gasta crédito). */
    public function tips(Request $request): RedirectResponse
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('mes')) ? Carbon::createFromFormat('Y-m-d', $request->input('mes').'-01')->startOfDay() : today()->startOfMonth();
        $label = TransactionFilters::monthName($month);

        try {
            $tips = $this->asAgent($request, fn () => $this->fox->tips(ExecutionContext::current()->organization, $label, self::facts(Indicators::for($month))));
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['fox' => $e instanceof \RuntimeException ? $e->getMessage() : 'A raposa está indisponível agora.']);
        }
        if ($tips['sentences'] === [] && ! $tips['suggestion']) {
            return back()->withErrors(['fox' => 'A raposa não conseguiu escrever as dicas agora. Tente de novo mais tarde.']);
        }
        AiNote::updateOrCreate(['kind' => 'tips', 'month' => $month->format('Y-m')], ['content' => $tips]);

        return back()->with('status', 'A raposa escreveu sobre o seu mês.');
    }

    /**
     * Os fatos do mês, já calculados e escritos com os valores formatados — é só isso que vai para a IA
     * (nada de descrição de lançamento, conta ou nome).
     *
     * @return string[]
     */
    public static function facts(Indicators $i): array
    {
        $m = fn (int $c) => Money::format($c);
        $s = $i->summary();
        $facts = ["Entrou {$m($s['income'])} e saiu {$m($s['expense'])}; sobrou {$m($s['saved'])}."];
        if ($s['savings_rate'] !== null) {
            $facts[] = 'Guardou '.round($s['savings_rate'] * 100).'% da renda'.($s['avg_savings_rate'] !== null ? ' (nos 3 meses antes, '.round($s['avg_savings_rate'] * 100).'%)' : '').'.';
        }
        if ($pace = $i->pace()) {
            $facts[] = "No ritmo atual, o mês fecha em {$m($pace['projected'])} de gastos ({$m($pace['per_day'])} por dia, faltam {$pace['days_left']} dias).";
        }
        foreach ($i->rises() as $r) {
            $facts[] = "{$r['name']} subiu: média de {$m($r['avg'])} nos 3 meses antes, {$m($r['total'])} neste mês (+{$m($r['delta'])}).";
        }
        $small = $i->smallSpending();
        if ($small['count'] > 0) {
            $facts[] = "Gastinhos (compras até R$ 30): {$small['count']} no mês, somando {$m($small['total'])}; nesse ritmo, {$m($small['per_year'])} por ano.";
        }
        $subs = $i->subscriptions();
        if ($subs->isNotEmpty()) {
            $facts[] = 'Assinaturas: '.$subs->map(fn ($x) => Str::limit($x['label'], 30).' '.$m($x['monthly']))->join(', ')." — {$m($subs->sum('yearly'))} por ano.";
        }
        $reserve = $i->reserve();
        if ($reserve['months'] !== null) {
            $facts[] = 'A reserva na poupança ('.$m($reserve['saved']).') cobre '.number_format($reserve['months'], 1, ',', '.').' meses de gastos.';
        } elseif (! $reserve['has_savings_account']) {
            $facts[] = 'Não há conta poupança cadastrada para a reserva de emergência.';
        }
        $cut = $i->cutInHalf();
        if ($cut['categories']->isNotEmpty()) {
            $facts[] = 'Gastando a metade em '.$cut['categories']->pluck('name')->join(', ', ' e ')." guardaria {$m($cut['per_year'])} por ano.";
        }
        foreach ($i->budgets()->where('status', '!=', 'ok') as $b) {
            $facts[] = "Orçamento de {$b['category']->name}: {$m($b['spent'])} de {$m($b['limit'])} (".round($b['pct'] * 100).'%).';
        }

        return array_slice($facts, 0, 40);
    }

    private function asAgent(Request $request, callable $callback): mixed
    {
        return $this->contexts->run(ExecutionContext::current()->asAiAgentFor((string) $request->user()->getKey()), $callback);
    }

    private function json(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => ['code' => 'unavailable', 'message' => 'A raposa está indisponível agora. Tente de novo em instantes.']]);
        }
    }
}
