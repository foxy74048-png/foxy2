<?php

namespace App\Finance\Http;

use App\Finance\Accounts\Account;
use App\Finance\Categories\Category;
use App\Finance\Statements\StatementImport;
use App\Finance\Statements\StatementImporter;
use App\Finance\Statements\StatementLine;
use App\Finance\Statements\StatementParser;
use App\Finance\Statements\StatementResolver;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use App\Platform\Imports\RawTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Importar extrato, fatura ou planilha antiga (docs/12, seção 5; copiado do Foxy Studio). OFX entra direto;
 * planilha e PDF pedem as colunas uma vez por conta (fica guardado para a próxima). Depois, cada linha é
 * revisada em StatementLineController. Sem integração com banco: a pessoa baixa o arquivo no app do banco.
 */
class StatementController
{
    private const SESSION_KEY = 'finance.statement_upload';

    public function __construct(
        private StatementParser $parser,
        private StatementImporter $importer,
        private RawTable $reader,
    ) {}

    public function index(Request $request): View
    {
        $tab = in_array($request->query('aba'), ['resolvidas', 'escondidas'], true) ? $request->query('aba') : 'revisar';
        $account = $request->query('conta');

        $lines = StatementLine::query()
            ->when($tab === 'escondidas', fn ($q) => $q->whereNotNull('hidden_at'), fn ($q) => $q->whereNull('hidden_at'))
            ->when($tab === 'revisar', fn ($q) => $q->where('status', StatementLine::PENDING))
            ->when($tab === 'resolvidas', fn ($q) => $q->where('status', '!=', StatementLine::PENDING))
            ->when($account, fn ($q, $id) => $q->where('account_id', $id))
            ->with(['account', 'suggestedCategory.parent', 'transaction.category.parent'])
            ->orderByDesc('date')->orderByDesc('time')->orderBy('id')
            ->paginate(50)->withQueryString();

        $collection = $lines->getCollection();
        $accounts = Account::query()->where('kind', '!=', Account::PEOPLE)->orderByRaw('archived_at is not null')->orderBy('name')->get();

        return view('finance.statements.index', [
            'tab' => $tab,
            'lines' => $lines,
            'matches' => $tab === 'revisar' ? $this->importer->matches($collection) : [],
            'duplicates' => $tab === 'revisar' ? $this->importer->possibleDuplicates($collection) : [],
            'accounts' => $accounts,
            'activeAccounts' => $accounts->whereNull('archived_at')->values(),
            'categoryOptions' => Category::options(),
            'pendingCount' => StatementLine::where('status', StatementLine::PENDING)->whereNull('hidden_at')->count(),
            'withCategoryCount' => StatementLine::where('status', StatementLine::PENDING)->whereNull('hidden_at')->whereNotNull('suggested_category_id')->count(),
            'withoutCategoryCount' => StatementLine::where('status', StatementLine::PENDING)->whereNull('hidden_at')->whereNull('suggested_category_id')->count(),
            'foxReady' => \App\Finance\Assistant\FinanceFox::isReady(),
            'hiddenCount' => StatementLine::whereNotNull('hidden_at')->count(),
            'recentImports' => StatementImport::with('account')->withCount(['lines as pending_count' => fn ($q) => $q->where('status', StatementLine::PENDING)])
                ->latest()->limit(6)->get(),
            'filterAccount' => $account,
            // Carteira do negócio: a saída pode ser gasto pessoal de um sócio (vira retirada dele).
            'partners' => $request->attributes->get('finance.wallet')->isBusiness() ? \App\Finance\Partners\Partner::query()->whereNull('archived_at')->orderBy('name')->get() : collect(),
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'string', 'max:30'],
            'file' => ['required', 'file', 'max:5120'],
        ], [], ['account_id' => 'conta', 'file' => 'arquivo']);
        $account = Account::active()->find($data['account_id']);
        if (! $account) {
            throw ValidationException::withMessages(['account_id' => 'Escolha a conta ou o cartão deste extrato.']);
        }

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $content = (string) file_get_contents($file->getRealPath());

        try {
            if (in_array($extension, ['ofx', 'qfx'], true) || stripos(substr($content, 0, 500), 'OFX') !== false) {
                $parsed = $this->parser->parseOfx($content);

                return $this->finish($account, 'ofx', $file->getClientOriginalName(), $parsed['rows']);
            }
            if (! in_array($extension, ['csv', 'txt', 'xlsx', 'xls', 'pdf'], true)) {
                return back()->withErrors(['file' => 'Envie um arquivo .ofx, .xlsx, .csv ou .pdf (baixe no app do banco, em "extrato" ou "fatura").']);
            }
            $table = $this->reader->read($file->getRealPath(), $extension);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        // Planilha/PDF: cada banco monta de um jeito. Guarda a tabela e pede as colunas.
        $path = 'finance-uploads/'.ExecutionContext::current()->organization->getKey().'/'.Str::uuid().'.json';
        Storage::disk('local')->put($path, json_encode($table));
        $request->session()->put(self::SESSION_KEY, [
            'path' => $path, 'account_id' => $account->id, 'file_name' => $file->getClientOriginalName(),
            'format' => $extension === 'txt' ? 'csv' : ($extension === 'xls' ? 'xlsx' : $extension),
        ]);

        return redirect()->route('finance.statements.mapping');
    }

    public function mapping(Request $request): View|RedirectResponse
    {
        $upload = $this->upload_($request);
        if (! $upload) {
            return redirect()->route('finance.statements');
        }
        $table = json_decode(Storage::disk('local')->get($upload['path']), true) ?: [];
        $account = Account::findOrFail($upload['account_id']);

        // PDF costuma começar com nome, conta e período: a prévia começa no cabeçalho, se ele for achado.
        $headerIndex = $this->headerRowIndex($table);
        $guess = $this->guessColumns($table[$headerIndex] ?? []);
        $saved = $account->statement_mapping;

        return view('finance.statements.mapping', [
            'upload' => $upload,
            'account' => $account,
            'preview' => array_slice($table, $headerIndex, 8),
            'columns' => max(array_map('count', array_slice($table, 0, 40)) ?: [1]),
            // Já usou planilha nesta conta: as mesmas colunas de antes. Senão, o palpite pelo cabeçalho.
            'guess' => $saved ? array_intersect_key($saved, $guess) + $guess : $guess,
            'hasHeader' => $saved['has_header'] ?? true,
            // Fatura de cartão em planilha costuma trazer a compra positiva.
            'invert' => $saved['invert_sign'] ?? $account->isCard(),
            'fromSaved' => (bool) $saved,
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $upload = $this->upload_($request);
        if (! $upload) {
            return redirect()->route('finance.statements')->withErrors(['file' => 'O arquivo expirou — envie de novo.']);
        }
        $data = $request->validate([
            'date' => ['required', 'integer', 'min:0'],
            'description' => ['required', 'integer', 'min:0'],
            'amount' => ['required', 'integer', 'min:0'],
            'debit' => ['nullable', 'integer', 'min:0', 'different:amount'],
            'category' => ['nullable', 'integer', 'min:0'],
            'has_header' => ['nullable', 'boolean'],
            'invert_sign' => ['nullable', 'boolean'],
        ], [], ['date' => 'coluna da data', 'description' => 'coluna da descrição', 'amount' => 'coluna do valor', 'debit' => 'coluna de saída']);

        $rows = $this->parser->csvRows(
            json_decode(Storage::disk('local')->get($upload['path']), true) ?: [],
            (int) $data['date'], (int) $data['description'], (int) $data['amount'],
            $request->boolean('has_header'), $request->boolean('invert_sign'),
            isset($data['debit']) ? (int) $data['debit'] : null,
            isset($data['category']) ? (int) $data['category'] : null,
        );
        $skipped = $this->parser->skipped;

        if ($rows === []) {
            return back()->withErrors(['date' => 'Nenhuma linha válida com essas colunas — confira qual coluna é a data e qual é o valor.']);
        }

        $account = Account::findOrFail($upload['account_id']);
        $account->update(['statement_mapping' => [
            'date' => (int) $data['date'], 'description' => (int) $data['description'], 'amount' => (int) $data['amount'],
            'debit' => isset($data['debit']) ? (int) $data['debit'] : null, 'category' => isset($data['category']) ? (int) $data['category'] : null,
            'has_header' => $request->boolean('has_header'), 'invert_sign' => $request->boolean('invert_sign'),
        ]]);
        Storage::disk('local')->delete($upload['path']);
        $request->session()->forget(self::SESSION_KEY);

        return $this->finish($account, $upload['format'], $upload['file_name'], $rows, $skipped);
    }

    public function cancel(Request $request): RedirectResponse
    {
        if ($upload = $request->session()->pull(self::SESSION_KEY)) {
            Storage::disk('local')->delete($upload['path']);
        }

        return redirect()->route('finance.statements');
    }

    /**
     * Desfaz uma importação: apaga as linhas dela que ainda estão para revisar (o mesmo arquivo pode ser
     * enviado de novo). Com "tudo", também desfaz o que já foi lançado por ela (ex.: planilha antiga importada
     * com as colunas trocadas). Fica na lista, em cinza.
     */
    public function undo(Request $request, StatementImport $import, StatementResolver $resolver): RedirectResponse
    {
        if ($import->undone_at !== null) {
            return redirect()->route('finance.statements')->with('status', 'Essa importação já tinha sido desfeita.');
        }

        $all = $request->boolean('tudo');
        $undoneResolved = 0;
        if ($all) {
            foreach ($import->lines()->where('status', '!=', StatementLine::PENDING)->get() as $line) {
                $resolver->undo($line);
                $undoneResolved++;
            }
        }
        $removed = $import->lines()->where('status', StatementLine::PENDING)->delete();
        $kept = $import->lines()->count();
        $import->update(['undone_at' => now()]);
        Audit::record('finance.statement.import_undone', subject: $import, metadata: ['removed' => $removed, 'all' => $all]);

        return redirect()->route('finance.statements')->with('status', "{$removed} linha(s) apagada(s)"
            .($undoneResolved ? ", incluindo {$undoneResolved} que já tinham sido lançadas (os lançamentos foram apagados)" : '')
            .'.'.($kept > 0 ? " {$kept} já resolvida(s) continuam nos lançamentos." : ' Pode enviar o arquivo de novo.'));
    }

    /**
     * Tira da lista linhas já resolvidas (as selecionadas ou todas as ignoradas). Esconde em vez de apagar: a
     * linha continua no controle de repetidos e não volta se importar o mesmo período.
     */
    public function clear(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:selected,ignored'],
            'line_ids' => ['array', 'required_if:scope,selected'],
            'line_ids.*' => ['string', 'max:30'],
        ], ['line_ids.required_if' => 'Selecione as linhas que quer tirar da lista.']);

        $query = StatementLine::query()->where('status', '!=', StatementLine::PENDING)->whereNull('hidden_at');
        $data['scope'] === 'ignored' ? $query->where('status', StatementLine::IGNORED) : $query->whereIn('id', $data['line_ids']);
        $removed = $query->update(['hidden_at' => now(), 'updated_at' => now()]);

        return redirect()->route('finance.statements', ['aba' => 'resolvidas'])->with('status', "{$removed} linha(s) tirada(s) da lista. Elas não voltam se você importar o mesmo período de novo.");
    }

    public function unhide(StatementLine $line): RedirectResponse
    {
        if ($line->hidden_at === null) {
            return back()->with('status', 'Essa linha já está na lista.');
        }
        $line->update($line->status === StatementLine::IGNORED
            ? ['hidden_at' => null, 'status' => StatementLine::PENDING, 'resolved_at' => null]
            : ['hidden_at' => null]);

        return back()->with('status', $line->isPending() ? 'A linha voltou para "Para revisar".' : 'A linha voltou para "Já resolvidas".');
    }

    /** @param  array<int, string>  $skipped */
    private function finish(Account $account, string $format, ?string $fileName, array $rows, array $skipped = []): RedirectResponse
    {
        if ($rows === []) {
            return redirect()->route('finance.statements')->withErrors(['file' => 'Nenhum movimento encontrado nesse arquivo.']);
        }

        $import = $this->importer->import($account, $format, $fileName, $rows);
        $already = $import->lines_count - $import->new_lines_count;
        Audit::record('finance.statement.imported', subject: $import, metadata: ['new' => $import->new_lines_count, 'format' => $format]);

        return redirect()->route('finance.statements')
            ->with('status', "{$import->new_lines_count} linha(s) nova(s) para revisar em {$account->name}".($already > 0 ? " — {$already} já tinham sido importadas antes e foram puladas." : '.'))
            ->withErrors($skipped ? ['file' => count($skipped).' linha(s) com data ficaram de fora porque o valor não foi entendido (ex.: '.implode('; ', array_map(fn ($l) => '“'.Str::limit($l, 70).'”', array_slice($skipped, 0, 3))).'). Confira a coluna do valor ou mande o arquivo para o suporte.'] : []);
    }

    private function upload_(Request $request): ?array
    {
        $upload = $request->session()->get(self::SESSION_KEY);

        return $upload && Storage::disk('local')->exists($upload['path']) && Account::find($upload['account_id']) ? $upload : null;
    }

    /**
     * Palpite das colunas pelo cabeçalho (do Studio), mais a de categoria (planilha antiga).
     *
     * @return array{date: ?int, description: ?int, amount: ?int, debit: ?int, category: ?int}
     */
    private function guessColumns(array $header): array
    {
        $find = function (array $needles) use ($header): ?int {
            foreach ($header as $i => $cell) {
                $cell = Str::of((string) $cell)->ascii()->lower()->toString();
                foreach ($needles as $needle) {
                    if (str_contains($cell, $needle)) {
                        return $i;
                    }
                }
            }

            return null;
        };

        return [
            // "Hora" só quando não há "Data": extrato agrupado por dia (PicPay).
            'date' => $find(['data', 'date']) ?? $find(['hora']),
            'description' => $find(['descri', 'hist', 'titulo', 'title', 'estabelecimento', 'lancamento', 'memo', 'origem', 'destino', 'favorecido']),
            'amount' => $find(['valor', 'amount', 'quantia', 'total']) ?? $find(['credito', 'entrada', 'deposito']),
            'debit' => $find(['debito', 'saida', 'retirada']),
            'category' => $find(['categoria', 'category', 'grupo', 'classifica']),
        ];
    }

    /** Primeira linha (entre as 40 primeiras) com cara de cabeçalho: tem "valor" e "data"/"hora". */
    private function headerRowIndex(array $table): int
    {
        foreach (array_slice($table, 0, 40, true) as $i => $row) {
            $guess = $this->guessColumns($row);
            if ($guess['date'] !== null && $guess['amount'] !== null) {
                return $i;
            }
        }

        return 0;
    }
}
