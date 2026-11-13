<?php

namespace App\Commerce\Supply;

use App\Commerce\Catalog\GridMatcher;
use App\Commerce\Catalog\Product;
use App\Commerce\Catalog\Variant;
use App\Commerce\Finance\FinanceService;
use App\Commerce\Inventory\StockService;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Remessa de fornecedor: rascunho a partir do comprovante lido → conferência (produto, categoria, origem e
 * preços por produto) → lançamento (produtos/variantes pelo código de barras, estoque, conta a pagar das
 * compras) → acerto da consignação (devolve o que sobrou e lança o valor do vendido).
 */
class SupplyService
{
    public function __construct(private GridMatcher $grid, private StockService $stock, private FinanceService $finance, private SupplierMemory $memory) {}

    /**
     * Rascunho para conferir: grupos com preço de venda e custo sugeridos. A fornecedora é reconhecida pelo
     * CNPJ/nome e as peças já conferidas antes voltam no produto, cor e tamanho de antes. Sem $basis/$percent,
     * vale o jeito da última remessa desta fornecedora.
     */
    public function draft(array $parsed, ?string $basis = null, ?float $percent = null): SupplyBatch
    {
        if ($parsed['items'] === []) {
            throw ValidationException::withMessages(['file' => 'Não encontramos itens neste documento. Confira se é o PDF do comprovante, o XML da nota fiscal ou cole o texto do comprovante.']);
        }

        $supplier = $this->memory->supplier($parsed);
        $percent ??= $basis === null || $basis === $supplier->price_basis ? $supplier->percent : ($basis === 'custo' ? 100.0 : 30.0);
        $basis ??= $supplier->price_basis;
        $mode = ($parsed['source'] ?? null) === 'nfe' ? (empty($parsed['consigned']) ? 'compra' : 'consignado') : $supplier->mode;

        $groups = array_map(function (array $group) use ($basis, $percent, $mode) {
            $doc = (int) round(collect($group['lines'])->avg('price_cents'));
            [$sale, $cost] = self::prices($doc, $basis, $percent);
            $match = ! empty($group['product_id']) ? Product::with('variants')->find($group['product_id']) : $this->existing($group);

            return ['mode' => $mode, 'category_id' => $match?->category_id, 'sale_cents' => $match ? (int) $match->variants->min('price_cents') : $sale,
                'cost_cents' => $cost, 'doc_price_cents' => $doc, 'product_id' => $match?->id, 'product_name' => $match?->name] + $group;
        }, $this->memory->recall($supplier, ReceiptParser::group($parsed['items'])));

        $batch = SupplyBatch::create([
            'supplier' => Str::limit($supplier->name, 120, ''), 'supplier_id' => $supplier->id, 'source' => $parsed['source'] ?? 'comprovante',
            'installments' => ($parsed['installments'] ?? []) ?: null,
            'document_number' => $parsed['number'], 'received_on' => $parsed['date'] ?? today()->toDateString(), 'closing_on' => $parsed['closing'],
            'price_basis' => $basis, 'percent' => $percent,
            'total_cents' => (int) collect($parsed['items'])->sum(fn ($i) => $i['price_cents'] * $i['quantity']),
            'groups' => $groups, 'created_by' => $this->userId(),
        ]);
        Audit::record('commerce.supply.drafted', subject: $batch, metadata: ['items' => count($parsed['items'])]);

        return $batch;
    }

    /**
     * Preço de venda e custo a partir do preço do comprovante. "venda": é o preço da cliente e a loja fica com
     * a comissão (%); "custo": é o que a loja paga e a venda leva a margem (%), arredondada para ,90.
     *
     * @return array{0: int, 1: int}
     */
    public static function prices(int $docCents, string $basis, float $percent): array
    {
        if ($basis === 'custo') {
            $sale = (int) round($docCents * (1 + $percent / 100));

            return [max($docCents, (int) (ceil($sale / 100) * 100) - 10), $docCents];
        }

        return [$docCents, (int) round($docCents * (1 - min($percent, 100) / 100))];
    }

    /**
     * Lança a remessa conferida. $groups = os grupos do rascunho com o que foi ajustado na tela.
     */
    public function confirm(SupplyBatch $batch, array $groups): SupplyBatch
    {
        if ($batch->status !== 'rascunho') {
            throw ValidationException::withMessages(['status' => 'Esta remessa já foi lançada.']);
        }

        return DB::transaction(function () use ($batch, $groups) {
            $reason = 'Remessa '.$batch->label();
            $purchase = 0;
            $supplier = $batch->supplier_id ? Supplier::find($batch->supplier_id) : null;
            foreach ($groups as $group) {
                if (! empty($group['skip'])) {
                    continue;
                }
                $product = $this->productFor($group, $batch->supplier);
                foreach ($group['lines'] as $line) {
                    $variant = $this->variantFor($product, $line, $group);
                    $variant->update(['gtin' => $line['barcode'] ?: $variant->gtin, 'cost_cents' => $group['cost_cents'], 'status' => 'active']);
                    $this->stock->receive($variant, (int) $line['quantity'], $reason);
                    // Linha acrescentada à mão só ensina pelo código de barras (a descrição dela não veio do documento).
                    if ($supplier && (empty($line['manual']) || ! empty($line['barcode']))) {
                        $this->memory->remember($supplier, $line, $variant);
                    }
                    SupplyItem::create([
                        'batch_id' => $batch->id, 'variant_id' => $variant->id, 'description' => Str::limit($line['description'], 200, ''),
                        'barcode' => $line['barcode'] ?: null, 'mode' => $group['mode'], 'quantity' => (int) $line['quantity'],
                        'document_price_cents' => (int) $line['price_cents'], 'cost_cents' => (int) $group['cost_cents'],
                    ]);
                    if ($group['mode'] === 'compra') {
                        $purchase += (int) $group['cost_cents'] * (int) $line['quantity'];
                    }
                }
            }

            if ($purchase > 0) {
                $this->purchasePayables($batch, $reason, $purchase);
            }
            if ($supplier) {
                $modes = collect($groups)->reject(fn ($g) => ! empty($g['skip']))->countBy('mode');
                $supplier->update(['name' => $batch->supplier, 'price_basis' => $batch->price_basis, 'percent' => $batch->percent]
                    + ($modes->isNotEmpty() && $batch->source !== 'nfe' ? ['mode' => $modes->sortDesc()->keys()->first()] : []));
            }
            $batch->update(['status' => 'aberta', 'groups' => null]);
            Audit::record('commerce.supply.confirmed', subject: $batch, metadata: ['purchase' => $purchase]);

            return $batch;
        });
    }

    /**
     * Acerto da consignação: o que volta sai do estoque (devolvido à fornecedora); o vendido vira conta a pagar.
     *
     * @param  array<string, int>  $returns  item => quantidade devolvida
     */
    public function settle(SupplyBatch $batch, array $returns, ?Carbon $due = null): int
    {
        if ($batch->status !== 'aberta') {
            throw ValidationException::withMessages(['status' => 'Esta remessa não está em aberto.']);
        }

        return DB::transaction(function () use ($batch, $returns, $due) {
            $owed = 0;
            foreach ($batch->items()->with('variant')->where('mode', 'consignado')->get() as $item) {
                $back = max(0, min($item->quantity, (int) ($returns[$item->id] ?? 0)));
                if ($back > 0 && $item->variant) {
                    $this->stock->remove($item->variant, $back, 'Devolvido no acerto da remessa '.$batch->label());
                }
                $sold = $item->quantity - $back;
                $item->update(['returned' => $back, 'sold' => $sold]);
                $owed += $sold * $item->cost_cents;
            }
            if ($owed > 0) {
                $this->finance->payables(['description' => 'Acerto da consignação '.$batch->label(), 'supplier' => $batch->supplier, 'category' => 'fornecedor',
                    'amount_cents' => $owed, 'due_date' => ($due ?? $batch->closing_on ?? today())->toDateString()]);
            }
            $batch->update(['status' => 'acertada', 'settled_at' => now()]);
            Audit::record('commerce.supply.settled', subject: $batch, metadata: ['owed' => $owed]);

            return $owed;
        });
    }

    /**
     * Conta a pagar das peças compradas: nas parcelas da nota (duplicatas), proporcional ao valor de cada
     * uma, ou numa conta só no fechamento.
     */
    private function purchasePayables(SupplyBatch $batch, string $reason, int $purchase): void
    {
        $dues = $batch->installments ?? [];
        $total = array_sum(array_column($dues, 'cents'));
        if (count($dues) < 2 || $total <= 0) {
            $this->finance->payables(['description' => $reason.' (peças compradas)', 'supplier' => $batch->supplier, 'category' => 'fornecedor',
                'amount_cents' => $purchase, 'due_date' => ($dues[0]['due'] ?? $batch->closing_on?->toDateString()) ?? today()->toDateString()]);

            return;
        }
        $left = $purchase;
        foreach ($dues as $i => $due) {
            $amount = $i === count($dues) - 1 ? $left : (int) round($purchase * $due['cents'] / $total);
            $left -= $amount;
            $this->finance->payables(['description' => $reason.' (peças compradas), parcela '.($i + 1).'/'.count($dues), 'supplier' => $batch->supplier,
                'category' => 'fornecedor', 'amount_cents' => $amount, 'due_date' => $due['due']]);
        }
    }

    private function existing(array $group): ?Product
    {
        return $this->grid->existing($group);
    }

    private function productFor(array $group, string $supplier): Product
    {
        $product = $this->grid->productFor($group, ['brand' => $supplier], $supplier);
        $product->update(['supplier' => $supplier, 'supply_mode' => $group['mode'], 'category_id' => $group['category_id'] ?: $product->category_id]);

        return $product;
    }

    private function variantFor(Product $product, array $line, array $group): Variant
    {
        return $this->grid->variantFor($product, $line, (int) $group['sale_cents']);
    }

    private function userId(): ?string
    {
        $context = ExecutionContext::current();

        return $context->principalType === ExecutionContext::PRINCIPAL_USER ? (string) $context->principalId : null;
    }
}
