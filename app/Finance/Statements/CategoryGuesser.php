<?php

namespace App\Finance\Statements;

use App\Finance\Categories\Category;
use App\Finance\Transactions\Transaction;
use Illuminate\Support\Str;

/**
 * Categoria sugerida para uma linha importada, sem IA (a categorização automática completa, com regras e a
 * raposa, é a fatia 4):
 * 1. a planilha antiga tem coluna de categoria → a categoria com esse nome (criada se não existir);
 * 2. a mesma descrição já foi lançada antes nesta carteira → a categoria usada da última vez.
 * Uma instância por importação (guarda o que já procurou).
 */
class CategoryGuesser
{
    private ?\Illuminate\Support\Collection $categories = null;

    private array $byDescription = [];

    public function guess(string $description, string $kind, ?string $columnName = null): ?Category
    {
        if ($columnName !== null && trim($columnName) !== '') {
            return $this->byName(trim($columnName), $kind);
        }

        $key = self::key($description);
        if ($key === '') {
            return null;
        }
        if (! array_key_exists($kind.$key, $this->byDescription)) {
            $this->byDescription[$kind.$key] = $this->lastUsed($key, $kind);
        }

        return $this->byDescription[$kind.$key];
    }

    /** Descrição sem número, data, acento e pontuação ("IFOOD *PEDIDO 1234" → "ifood pedido"). */
    public static function key(string $description): string
    {
        $text = Str::lower(Str::ascii($description));
        $text = preg_replace('/[^a-z ]+/', ' ', $text);

        return Str::limit(trim(preg_replace('/\s+/', ' ', $text)), 40, '');
    }

    private function byName(string $name, string $kind): Category
    {
        $this->categories ??= Category::query()->get();
        $wanted = Str::lower(Str::ascii($name));
        $found = $this->categories->first(fn (Category $c) => $c->kind === $kind && Str::lower(Str::ascii($c->name)) === $wanted);

        if (! $found) {
            $found = Category::create(['kind' => $kind, 'name' => Str::limit($name, 60, '')]);
            $this->categories->push($found);
        }

        return $found;
    }

    /** Os últimos lançamentos com categoria, carregados uma vez por importação (comparação em PHP: igual no SQLite e no PostgreSQL, sem depender de maiúscula/acento no banco). */
    private ?array $recent = null;

    private function lastUsed(string $key, string $kind): ?Category
    {
        if ($this->recent === null) {
            $this->recent = [];
            Transaction::query()->whereIn('kind', [Transaction::EXPENSE, Transaction::INCOME])->whereNotNull('category_id')
                ->whereHas('category', fn ($q) => $q->whereNull('hidden_at'))
                ->orderByDesc('date')->limit(2000)->with('category')->get()
                ->each(function (Transaction $t) {
                    $this->recent[$t->kind.self::key($t->description)] ??= $t->category;
                });
        }

        return $this->recent[$kind.$key] ?? null;
    }
}
