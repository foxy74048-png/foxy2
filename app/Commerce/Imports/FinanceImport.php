<?php

namespace App\Commerce\Imports;

use App\Commerce\Finance\FinanceService;
use App\Commerce\Finance\Payable;
use App\Commerce\Orders\Customer;
use App\Platform\Audit\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Migração do financeiro: o caderninho do fiado (contas a receber, uma linha por parcela) e as contas da loja
 * (a pagar). Cliente achada pelo WhatsApp ou pelo nome; se não existir, é cadastrada. Linha marcada como paga
 * entra já baixada (fica no histórico e no "recebido/pago no mês").
 */
class FinanceImport
{
    public const KINDS = ['receber' => 'Contas a receber (fiado/crediário)', 'pagar' => 'Contas a pagar'];

    public const COLUMNS = [
        'customer' => ['cliente', 'nome', 'nome da cliente', 'nome do cliente', 'devedor', 'compradora'],
        'phone' => ['whatsapp', 'telefone', 'celular', 'fone', 'contato', 'tel'],
        'description' => ['descricao', 'referente', 'historico', 'produto', 'produtos', 'compra', 'observacao', 'conta', 'despesa'],
        'amount' => ['valor', 'valor da parcela', 'valor total', 'total', 'quanto', 'saldo', 'valor r'],
        'due' => ['vencimento', 'data de vencimento', 'vence em', 'data', 'dia', 'venc'],
        'paid' => ['situacao', 'status', 'pago', 'recebido', 'quitado', 'pagou'],
        'paid_on' => ['pago em', 'recebido em', 'data do pagamento', 'data de pagamento', 'pagamento'],
        'supplier' => ['fornecedor', 'fornecedora', 'favorecido', 'empresa', 'para quem'],
        'category' => ['categoria', 'tipo', 'grupo', 'plano de contas'],
        'installment' => ['parcela', 'n parcela', 'parcelas'],
    ];

    public const SAMPLE = [
        'receber' => [
            ['Cliente', 'WhatsApp', 'Descrição', 'Valor', 'Vencimento', 'Parcela', 'Situação'],
            ['Bruna Martins', '(11) 98111-2222', 'Conjunto renda preto', '43,30', '10/12/2026', '1/3', 'Aberto'],
            ['Bruna Martins', '(11) 98111-2222', 'Conjunto renda preto', '43,30', '10/01/2027', '2/3', 'Aberto'],
            ['Bruna Martins', '(11) 98111-2222', 'Conjunto renda preto', '43,30', '10/02/2027', '3/3', 'Aberto'],
            ['Carla Souza', '11 97333-4444', 'Camisola cetim', '139,90', '05/11/2026', '', 'Pago'],
        ],
        'pagar' => [
            ['Descrição', 'Fornecedor', 'Categoria', 'Valor', 'Vencimento', 'Situação'],
            ['Acerto remessa outubro', 'Miza', 'Fornecedor', '1.250,00', '20/11/2026', 'Aberto'],
            ['Aluguel da sala', 'Imobiliária', 'Aluguel', '800,00', '05/12/2026', 'Aberto'],
            ['Sacolas e etiquetas', 'Gráfica', 'Embalagens', '95,00', '01/11/2026', 'Pago'],
        ],
    ];

    public function __construct(private FinanceService $finance) {}

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array{rows: array, errors: array<int, string>, missing: string[]}
     */
    public function preview(string $kind, array $rows): array
    {
        $first = reset($rows) ?: [];
        $need = ['amount' => 'Valor', 'due' => 'Vencimento'] + ($kind === 'receber' ? ['customer' => 'Cliente'] : ['description' => 'Descrição']);
        $missing = array_values(array_filter($need, fn ($label, $field) => ! array_intersect(self::COLUMNS[$field], array_keys($first)), ARRAY_FILTER_USE_BOTH));
        if ($missing !== []) {
            return ['rows' => [], 'errors' => [], 'missing' => $missing];
        }

        $out = [];
        $errors = [];
        foreach ($rows as $n => $row) {
            $amountText = Sheet::pick($row, self::COLUMNS['amount']);
            $amount = Sheet::cents($amountText);
            $due = Sheet::date(Sheet::pick($row, self::COLUMNS['due']));
            $customer = Str::limit(preg_replace('/\s+/', ' ', Sheet::pick($row, self::COLUMNS['customer'])), 255, '');
            $description = Str::limit(Sheet::pick($row, self::COLUMNS['description']), 140, '');
            $installment = Sheet::pick($row, self::COLUMNS['installment']);

            if ($amount === null || $amount <= 0) {
                $errors[$n] = $amountText === '' ? 'sem valor' : "valor \"{$amountText}\" não é um número maior que zero";

                continue;
            }
            if ($due === null) {
                $errors[$n] = 'vencimento "'.Sheet::pick($row, self::COLUMNS['due']).'" não é uma data (use dd/mm/aaaa)';

                continue;
            }
            if ($kind === 'receber' && $customer === '') {
                $errors[$n] = 'sem o nome da cliente';

                continue;
            }
            if ($kind === 'pagar' && $description === '' && Sheet::pick($row, self::COLUMNS['supplier']) === '') {
                $errors[$n] = 'sem descrição';

                continue;
            }

            $paidText = Str::lower(Str::ascii(Sheet::pick($row, self::COLUMNS['paid'])));
            $paid = $paidText !== '' && preg_match('/^(pag|receb|quit|sim|ok|baixad|liquid|x$)/', $paidText) === 1;
            $out[] = [
                'row' => $n,
                'customer' => $customer,
                'phone' => preg_replace('/\D/', '', Sheet::pick($row, self::COLUMNS['phone'])),
                'supplier' => Str::limit(Sheet::pick($row, self::COLUMNS['supplier']), 120, ''),
                'category' => self::category(Sheet::pick($row, self::COLUMNS['category'])),
                'description' => trim(($description ?: ($kind === 'receber' ? 'Conta a receber' : Sheet::pick($row, self::COLUMNS['supplier']))).($installment !== '' && preg_match('/\d/', $installment) ? " ({$installment})" : '')),
                'amount_cents' => $amount,
                'due_date' => $due,
                'paid' => $paid,
                'paid_on' => $paid ? (Sheet::date(Sheet::pick($row, self::COLUMNS['paid_on'])) ?? $due) : null,
            ];
        }

        return ['rows' => $out, 'errors' => $errors, 'missing' => []];
    }

    /** @return array{created: int, paid: int, customers: int, total_open: int} */
    public function apply(string $kind, array $rows): array
    {
        return DB::transaction(function () use ($kind, $rows) {
            $summary = ['created' => 0, 'paid' => 0, 'customers' => 0, 'total_open' => 0];
            $customers = [];
            foreach ($rows as $r) {
                if ($kind === 'receber') {
                    $key = $r['phone'] !== '' ? 'f'.substr($r['phone'], -8) : 'n'.Str::lower($r['customer']);
                    $customerId = $customers[$key] ??= $this->customerId($r, $summary);
                    $receivable = $this->finance->installments(['customer_id' => $customerId, 'description' => $r['description'], 'notes' => 'Importado da planilha'], $r['amount_cents'], 1, Carbon::parse($r['due_date']))[0];
                    if ($r['paid']) {
                        $this->finance->receive($receivable, $r['amount_cents'], null, Carbon::parse($r['paid_on']));
                    }
                } else {
                    $payable = $this->finance->payables(['description' => $r['description'], 'supplier' => $r['supplier'] ?: null, 'category' => $r['category'],
                        'amount_cents' => $r['amount_cents'], 'due_date' => $r['due_date'], 'notes' => 'Importado da planilha'])[0];
                    if ($r['paid']) {
                        $this->finance->pay($payable, $r['amount_cents'], null, Carbon::parse($r['paid_on']));
                    }
                }
                $summary['created']++;
                $r['paid'] ? $summary['paid']++ : $summary['total_open'] += $r['amount_cents'];
            }
            Audit::record('commerce.import.finance', metadata: $summary + ['kind' => $kind]);

            return $summary;
        });
    }

    /** "Embalagens", "aluguel", "Fornecedor (mercadoria)" → chave; o resto vai para "outros". */
    public static function category(string $text): string
    {
        $t = Str::lower(Str::ascii(trim($text)));
        if ($t === '') {
            return 'outros';
        }
        foreach (Payable::CATEGORIES as $key => $label) {
            $l = Str::lower(Str::ascii($label));
            if ($t === $key || $t === $l || str_starts_with($l, $t) || str_starts_with($t, $key)) {
                return $key;
            }
        }

        return 'outros';
    }

    private function customerId(array $r, array &$summary): string
    {
        $found = null;
        if (strlen($r['phone']) >= 8) {
            $tail = substr($r['phone'], -8);
            $found = Customer::whereNotNull('phone')->get(['id', 'phone'])->first(fn ($c) => str_ends_with(preg_replace('/\D/', '', (string) $c->phone), $tail));
        }
        $found ??= Customer::whereRaw('lower(name) = ?', [Str::lower($r['customer'])])->first();
        if ($found) {
            if ($r['phone'] !== '' && blank($found->phone)) {
                $found->update(['phone' => $r['phone']]);
            }

            return $found->id;
        }
        $summary['customers']++;

        return Customer::create(['name' => $r['customer'], 'phone' => $r['phone'] ?: null])->id;
    }
}
