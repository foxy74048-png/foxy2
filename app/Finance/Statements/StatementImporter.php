<?php

namespace App\Finance\Statements;

use App\Finance\Accounts\Account;
use App\Finance\Categories\Categorizer;
use App\Finance\Transactions\Transaction;
use App\Platform\Context\ExecutionContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Grava as linhas do arquivo para revisar sem repetir o que já veio antes, e faz as sugestões da tela.
 * Adaptado do StatementImporter do Foxy Studio (docs/12, A-F2): a regra de repetidos é a mesma, mas por conta
 * (no Studio era por "nome da origem"); no lugar de "cobrança do cliente", a sugestão é o lançamento que já
 * existe (digitado ou do WhatsApp) e a categoria.
 */
class StatementImporter
{
    /** Diferença máxima de dias entre a linha do extrato e o lançamento que já existe. */
    public const MATCH_WINDOW_DAYS = 5;

    private const STOPWORDS = ['de', 'da', 'do', 'das', 'dos', 'e', 'pix', 'compra', 'pagamento', 'pag', 'cartao', 'debito', 'credito', 'transferencia', 'enviado', 'recebido', 'enviada', 'recebida'];

    /**
     * @param  array<int, array{date: string, time?: ?string, description: string, amount: float, external_id: ?string, category?: ?string}>  $rows
     */
    public function import(Account $account, string $format, ?string $fileName, array $rows): StatementImport
    {
        return DB::transaction(function () use ($account, $format, $fileName, $rows) {
            $import = StatementImport::create([
                'account_id' => $account->id, 'format' => $format, 'file_name' => $fileName ? Str::limit($fileName, 250, '') : null,
                'lines_count' => count($rows), 'created_by' => $this->userId(),
            ]);

            $keys = $this->dedupeKeys($rows, $account);
            $existing = StatementLine::query()->whereIn('dedupe_key', $keys)->pluck('dedupe_key')->flip();
            // Mesmo registro importado de novo (outro arquivo, período que se sobrepõe) não duplica.
            $pool = $this->existingPool($account, $rows);
            $categorizer = new Categorizer;
            $ruleHits = [];

            $new = 0;
            foreach ($rows as $i => $row) {
                $key = $keys[$i];
                $cents = (int) round(((float) $row['amount']) * 100);
                if (isset($existing[$key]) || $cents === 0) {
                    continue;
                }
                if (empty($row['external_id']) && $this->isAlreadyThere($pool, $row)) {
                    continue;
                }
                $existing[$key] = true;

                $kind = $cents > 0 ? Transaction::INCOME : Transaction::EXPENSE;
                // Planilha antiga com coluna de categoria: a dela. Senão, regra → última vez → lista de lojas.
                $suggestion = ! empty($row['category'])
                    ? ['category' => $categorizer->byName($row['category'], $kind), 'origin' => 'planilha']
                    : $categorizer->suggest($row['description'], $kind);
                if (($suggestion['origin'] ?? null) === 'regra') {
                    $ruleHits[] = $categorizer->ruleIdFor($row['description'], $kind);
                }
                StatementLine::create([
                    'import_id' => $import->id,
                    'account_id' => $account->id,
                    'date' => $row['date'],
                    'time' => $row['time'] ?? null,
                    'description' => mb_substr($row['description'], 0, 255),
                    'amount_cents' => $cents,
                    'dedupe_key' => $key,
                    'suggested_category_id' => $suggestion['category']->id ?? null,
                    'suggestion_origin' => $suggestion['origin'] ?? null,
                ]);
                $new++;
            }

            $categorizer->countHits($ruleHits);
            $import->update(['new_lines_count' => $new]);

            return $import;
        });
    }

    /**
     * "Já está lançado?": para cada linha para revisar, o lançamento que já existe e não veio de extrato — mesmo
     * tipo (ou transferência no sentido certo), mesmo valor, até 5 dias de diferença. Na mesma conta primeiro,
     * depois com palavras em comum, depois o dia mais próximo. Um lançamento não é sugerido para duas linhas.
     *
     * @param  Collection<int, StatementLine>  $lines
     * @return array<string, Transaction> id da linha => lançamento
     */
    public function matches(Collection $lines): array
    {
        $pending = $lines->where('status', StatementLine::PENDING)->sortBy('date');
        if ($pending->isEmpty()) {
            return [];
        }

        $candidates = Transaction::query()->whereNull('statement_line_id')
            ->whereBetween('date', [
                $pending->min('date')->copy()->subDays(self::MATCH_WINDOW_DAYS)->toDateString(),
                $pending->max('date')->copy()->addDays(self::MATCH_WINDOW_DAYS)->toDateString(),
            ])
            ->whereIn('amount_cents', $pending->map(fn ($l) => abs($l->amount_cents))->unique()->values())
            ->with('account')->get();

        $taken = [];
        $result = [];
        foreach ($pending as $line) {
            $best = $candidates
                ->filter(fn (Transaction $t) => ! isset($taken[$t->id]) && $t->amount_cents === abs($line->amount_cents) && $this->sameDirection($t, $line))
                ->map(fn (Transaction $t) => [
                    $t,
                    $this->touches($t, $line->account_id) ? 1 : 0,
                    count(array_intersect(self::words($line->description), self::words($t->description))),
                    abs($t->date->diffInDays($line->date)),
                ])
                ->filter(fn (array $c) => $c[3] <= self::MATCH_WINDOW_DAYS)
                ->sort(fn (array $a, array $b) => [$b[1], $b[2], $a[3]] <=> [$a[1], $a[2], $b[3]])
                ->first();

            if ($best) {
                $taken[$best[0]->id] = true;
                $result[$line->id] = $best[0];
            }
        }

        return $result;
    }

    /** O lançamento serve para essa linha? Valor igual e sentido igual (saída com saída, entrada com entrada). */
    public function compatible(Transaction $transaction, StatementLine $line): bool
    {
        return $transaction->statement_line_id === null && $transaction->amount_cents === abs($line->amount_cents) && $this->sameDirection($transaction, $line);
    }

    /**
     * "Possível repetido": linha para revisar com o mesmo dia e valor de uma já resolvida (lançada, conciliada,
     * dividida — ignorada não conta), com uma palavra em comum. Ex.: o mesmo Pix escrito de outro jeito.
     *
     * @return array<string, StatementLine>
     */
    public function possibleDuplicates(Collection $lines): array
    {
        $pending = $lines->where('status', StatementLine::PENDING);
        if ($pending->isEmpty()) {
            return [];
        }

        $resolved = StatementLine::query()
            ->whereIn('status', [StatementLine::CREATED, StatementLine::MATCHED, StatementLine::SPLIT])
            ->whereBetween('date', [$pending->min('date')->toDateString(), $pending->max('date')->toDateString()])
            ->get();

        $result = [];
        foreach ($pending as $line) {
            $words = array_filter(self::words($line->description), fn ($w) => mb_strlen($w) >= 4);
            $match = $resolved->first(fn (StatementLine $other) => $other->id !== $line->id
                && $other->date->isSameDay($line->date) && $other->amount_cents === $line->amount_cents
                && array_intersect($words, self::words($other->description)) !== []);
            if ($match) {
                $result[$line->id] = $match;
            }
        }

        return $result;
    }

    private function sameDirection(Transaction $t, StatementLine $line): bool
    {
        return match ($t->kind) {
            Transaction::INCOME => $line->isIncome(),
            Transaction::EXPENSE => ! $line->isIncome(),
            // Transferência: no extrato da conta de saída é saída; no da conta de chegada, entrada.
            default => $line->isIncome() ? $t->to_account_id === $line->account_id : $t->account_id === $line->account_id,
        };
    }

    private function touches(Transaction $t, string $accountId): bool
    {
        return $t->account_id === $accountId || $t->to_account_id === $accountId;
    }

    /** @return array<int, string> palavras que ajudam a reconhecer (sem acento, sem "pix", "compra"...). */
    public static function words(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $w) => mb_strlen($w) >= 3 && ! ctype_digit($w) && ! in_array($w, self::STOPWORDS, true))));
    }

    /** Linhas que já existem nesta conta (até as escondidas) nos dias do arquivo. */
    private function existingPool(Account $account, array $rows): array
    {
        $dates = array_values(array_unique(array_column($rows, 'date')));
        if ($dates === []) {
            return [];
        }

        return StatementLine::query()
            ->where('account_id', $account->id)
            ->whereBetween('date', [min($dates), max($dates)])
            ->get(['date', 'time', 'amount_cents', 'description'])
            ->map(fn (StatementLine $line) => [
                'date' => $line->date->toDateString(),
                'time' => $line->time,
                'cents' => $line->amount_cents,
                'description' => (string) $line->description,
                'used' => false,
            ])
            ->all();
    }

    /**
     * Já existe esse registro? Com horário dos dois lados: mesmo dia, horário e valor, com a descrição igual ou
     * uma palavra em comum. Sem horário: mesmo dia, valor e descrição. Cada linha existente só "cobre" uma do
     * arquivo — duas compras iguais continuam sendo duas.
     */
    private function isAlreadyThere(array &$pool, array $row): bool
    {
        $time = $row['time'] ?? null;
        $cents = (int) round(((float) $row['amount']) * 100);
        $text = $this->normalized($row['description']);
        $words = array_filter(self::words((string) $row['description']), fn ($w) => mb_strlen($w) >= 4);

        foreach ($pool as $i => $old) {
            if ($old['used'] || $old['date'] !== $row['date'] || $old['cents'] !== $cents) {
                continue;
            }
            $textMatches = $this->normalized($old['description']) === $text;
            $match = ($time !== null && $old['time'] !== null)
                ? $old['time'] === $time && ($textMatches || array_intersect($words, self::words($old['description'])) !== [])
                : $textMatches;
            if ($match) {
                $pool[$i]['used'] = true;

                return true;
            }
        }

        return false;
    }

    private function normalized(string $description): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim(mb_substr($description, 0, 255))));
    }

    /**
     * OFX traz um id por transação (FITID): usa ele, com a conta. Planilha não tem: data (+hora), valor,
     * descrição e a ordem da repetição no arquivo, para duas compras iguais no mesmo dia não virarem uma só.
     *
     * @return array<int, string>
     */
    private function dedupeKeys(array $rows, Account $account): array
    {
        $seen = [];
        $keys = [];
        foreach ($rows as $i => $row) {
            if (! empty($row['external_id'])) {
                $keys[$i] = sha1('ofx|'.$account->id.'|'.$row['external_id']);

                continue;
            }

            $base = implode('|', [$account->id, $row['date'].(empty($row['time']) ? '' : ' '.$row['time']), number_format((float) $row['amount'], 2, '.', ''), $this->normalized($row['description'])]);
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $keys[$i] = sha1('csv|'.$base.'|'.$seen[$base]);
        }

        return $keys;
    }

    private function userId(): ?string
    {
        $context = ExecutionContext::current();

        return $context->principalType === ExecutionContext::PRINCIPAL_USER ? (string) $context->principalId : null;
    }
}
