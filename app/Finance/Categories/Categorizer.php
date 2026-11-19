<?php

namespace App\Finance\Categories;

use App\Finance\Statements\StatementLine;
use App\Finance\Transactions\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Categoria sugerida para uma descrição, sem IA e sem custo (docs/12, seção 6), nesta ordem:
 * 1. regra da carteira ("sempre que aparecer IFOOD, é Delivery"), a mais longa que bater;
 * 2. a categoria usada da última vez na mesma descrição (sem números e acentos);
 * 3. a lista pronta de lojas conhecidas (config/finance_merchants.php), se a categoria existir na carteira.
 * A raposa entra depois, na fatia 8, só para o que sobrar.
 *
 * Uma instância guarda o que já carregou (usar uma por importação/requisição, na carteira do contexto).
 */
class Categorizer
{
    public const ORIGINS = ['regra' => 'pela sua regra', 'historico' => 'como da última vez', 'lista' => 'loja conhecida', 'planilha' => 'da planilha'];

    /** Palavras que não identificam a loja (ficam fora do padrão sugerido para a regra). */
    private const NOISE = ['pix', 'enviado', 'enviada', 'recebido', 'recebida', 'compra', 'compras', 'pagamento', 'pagto', 'pag', 'pgto', 'cartao', 'debito', 'credito', 'transferencia', 'transf', 'ted', 'doc', 'para', 'de', 'da', 'do', 'em', 'no', 'na', 'com', 'via', 'ltda', 'sa', 'me', 'eireli', 'br', 'brasil', 'parcela', 'parc', 'nacional', 'internacional', 'mastercard', 'visa', 'elo'];

    private ?Collection $rules = null;

    private ?Collection $categories = null;

    private ?array $recent = null;

    /** @return array{category: Category, origin: string}|null */
    public function suggest(string $description, string $kind): ?array
    {
        if (! in_array($kind, [Transaction::EXPENSE, Transaction::INCOME], true)) {
            return null;
        }
        $text = self::normalize($description);
        if ($text === '') {
            return null;
        }

        if ($rule = $this->ruleFor($text, $kind)) {
            return ['category' => $rule->category, 'origin' => 'regra'];
        }
        if ($category = $this->lastUsed($description, $kind)) {
            return ['category' => $category, 'origin' => 'historico'];
        }
        if ($category = $this->fromMerchantList($text, $kind)) {
            return ['category' => $category, 'origin' => 'lista'];
        }

        return null;
    }

    /** Planilha antiga com coluna de categoria: a categoria com esse nome (criada se não existir). */
    public function byName(string $name, string $kind): Category
    {
        $wanted = Str::lower(Str::ascii(trim($name)));
        $found = $this->categories()->first(fn (Category $c) => $c->kind === $kind && Str::lower(Str::ascii($c->name)) === $wanted);

        if (! $found) {
            $found = Category::create(['kind' => $kind, 'name' => Str::limit(trim($name), 60, '')]);
            $this->categories->push($found);
        }

        return $found;
    }

    /**
     * Guarda a regra (ou troca a categoria da que já existe com o mesmo padrão) e atualiza a sugestão das
     * linhas do extrato que ainda estão para revisar e batem com ela. Com $applyToUncategorized, também põe a
     * categoria nos lançamentos sem categoria que batem.
     *
     * @return array{rule: CategoryRule, lines: int, transactions: int}
     */
    public function remember(string $pattern, Category $category, bool $applyToUncategorized = false): array
    {
        $pattern = Str::limit(self::normalize($pattern), 80, '');

        return DB::transaction(function () use ($pattern, $category, $applyToUncategorized) {
            $rule = CategoryRule::updateOrCreate(
                ['kind' => $category->kind, 'pattern' => $pattern],
                ['category_id' => $category->id, 'origin' => 'pessoa'],
            );
            $this->rules = null;

            $lines = 0;
            StatementLine::query()->where('status', StatementLine::PENDING)
                ->where('amount_cents', $category->kind === Transaction::INCOME ? '>' : '<', 0)
                ->get()
                ->filter(fn (StatementLine $l) => self::contains(self::normalize($l->description), $pattern))
                ->each(function (StatementLine $l) use ($category, &$lines) {
                    $l->update(['suggested_category_id' => $category->id, 'suggestion_origin' => 'regra']);
                    $lines++;
                });

            $transactions = 0;
            if ($applyToUncategorized) {
                Transaction::query()->where('kind', $category->kind)->whereNull('category_id')->get()
                    ->filter(fn (Transaction $t) => self::contains(self::normalize($t->description), $pattern))
                    ->each(function (Transaction $t) use ($category, &$transactions) {
                        $t->forceFill(['category_id' => $category->id])->save();
                        $transactions++;
                    });
            }

            return ['rule' => $rule, 'lines' => $lines, 'transactions' => $transactions];
        });
    }

    /** Conta quantas vezes cada regra foi usada (chamado uma vez no fim da importação). */
    public function countHits(array $ruleIds): void
    {
        foreach (array_count_values(array_filter($ruleIds)) as $id => $count) {
            CategoryRule::whereKey($id)->increment('hits', $count);
        }
    }

    /** A regra que bateu na última sugestão por regra (para countHits). */
    public function ruleIdFor(string $description, string $kind): ?string
    {
        return $this->ruleFor(self::normalize($description), $kind)?->id;
    }

    /** Sem acento, minúsculas, só letras e números separados por um espaço ("IFOOD *Pedido 12" → "ifood pedido 12"). */
    public static function normalize(string $text): string
    {
        $text = Str::lower(Str::ascii($text));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '');
    }

    /** O padrão aparece como palavras inteiras? */
    public static function contains(string $normalizedText, string $normalizedPattern): bool
    {
        return $normalizedPattern !== '' && str_contains(' '.$normalizedText.' ', ' '.$normalizedPattern.' ');
    }

    /**
     * Padrão sugerido para a regra a partir de uma descrição: as 2 primeiras palavras que identificam a loja
     * ("Pix enviado - Drogaria São Paulo" → "drogaria sao"; "IFOOD *PEDIDO 77812" → "ifood pedido").
     */
    public static function defaultPattern(string $description): string
    {
        $words = array_values(array_filter(explode(' ', self::normalize($description)),
            fn ($w) => $w !== '' && ! in_array($w, self::NOISE, true) && ! preg_match('/\d{3,}/', $w) && strlen($w) >= 2));

        return implode(' ', array_slice($words, 0, 2)) ?: self::normalize($description);
    }

    private function ruleFor(string $text, string $kind): ?CategoryRule
    {
        $this->rules ??= CategoryRule::query()->with('category')->get()
            ->filter(fn (CategoryRule $r) => $r->category !== null)
            ->sortByDesc(fn (CategoryRule $r) => strlen($r->pattern))->values();

        return $this->rules->first(fn (CategoryRule $r) => $r->kind === $kind && self::contains($text, $r->pattern));
    }

    private function fromMerchantList(string $text, string $kind): ?Category
    {
        $merchants = config('finance_merchants', []);
        uksort($merchants, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($merchants as $pattern => $key) {
            if (self::contains($text, $pattern)) {
                $category = $this->categories()->first(fn (Category $c) => $c->system_key === $key && $c->kind === $kind && $c->hidden_at === null);
                if ($category) {
                    return $category;
                }
            }
        }

        return null;
    }

    /** A categoria da última vez: os últimos lançamentos com categoria, carregados uma vez (comparação em PHP, igual no SQLite e no PostgreSQL). */
    private function lastUsed(string $description, string $kind): ?Category
    {
        if ($this->recent === null) {
            $this->recent = [];
            Transaction::query()->whereIn('kind', [Transaction::EXPENSE, Transaction::INCOME])->whereNotNull('category_id')
                ->whereHas('category', fn ($q) => $q->whereNull('hidden_at'))
                ->orderByDesc('date')->orderByDesc('created_at')->limit(2000)->with('category')->get()
                ->each(function (Transaction $t) {
                    $this->recent[$t->kind.'|'.self::historyKey($t->description)] ??= $t->category;
                });
        }
        $key = self::historyKey($description);

        return $key === '' ? null : ($this->recent[$kind.'|'.$key] ?? null);
    }

    /** Descrição sem número ("IFOOD *PEDIDO 1234" e "IFOOD *PEDIDO 98" são a mesma coisa). */
    private static function historyKey(string $description): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z ]+/', ' ', Str::lower(Str::ascii($description))) ?? '')), 40, '');
    }

    private function categories(): Collection
    {
        return $this->categories ??= Category::query()->get();
    }
}
