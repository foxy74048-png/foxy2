<?php

namespace App\Commerce\Imports;

use App\Commerce\Catalog\Category;
use App\Commerce\Catalog\GridMatcher;
use App\Commerce\Catalog\Product;
use App\Commerce\Catalog\Variant;
use App\Commerce\Inventory\StockLevel;
use App\Commerce\Inventory\StockService;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Planilha de produtos e estoque: uma linha por variação (ex.: Sutiã Renda · Preto · 44 · 3 peças · 89,90).
 * Mesmo nome = mesmo produto; a grade (Cor/Tamanho) sai do que veio. Produto que já existe (código de barras,
 * SKU ou nome) só ganha as variações novas e o estoque; preço e custo da planilha atualizam a variação.
 */
class ProductImport
{
    public const COLUMNS = [
        'name' => ['produto', 'nome do produto', 'nome', 'descricao do produto', 'item', 'peca', 'descricao'],
        'category' => ['categoria', 'tipo de produto', 'departamento', 'grupo'],
        'size' => ['tamanho', 'tam', 'numeracao', 'numero', 'tamanhos'],
        'color' => ['cor', 'cores', 'estampa'],
        'quantity' => ['quantidade', 'qtd', 'qtde', 'quant', 'estoque', 'saldo', 'pecas'],
        'price' => ['preco de venda', 'preco', 'venda', 'valor de venda', 'valor', 'preco venda', 'pv'],
        'cost' => ['custo', 'preco de custo', 'valor de custo', 'custo unitario', 'preco custo'],
        'sku' => ['sku', 'codigo', 'cod', 'referencia', 'ref', 'codigo interno'],
        'barcode' => ['codigo de barras', 'ean', 'gtin', 'cod barras', 'codigo barras'],
        'supplier' => ['fornecedora', 'fornecedor', 'marca'],
        'mode' => ['origem', 'consignado', 'tipo'],
    ];

    /** Linhas de exemplo do modelo (loja de lingerie fictícia). */
    public const SAMPLE = [
        ['Produto', 'Categoria', 'Cor', 'Tamanho', 'Quantidade', 'Preço de venda', 'Custo', 'Código de barras', 'Fornecedora', 'Origem'],
        ['Sutiã Renda Floral', 'Sutiãs', 'Preto', '42', '3', '89,90', '35,00', '', 'Miza', 'Consignado'],
        ['Sutiã Renda Floral', 'Sutiãs', 'Preto', '44', '2', '89,90', '35,00', '', 'Miza', 'Consignado'],
        ['Sutiã Renda Floral', 'Sutiãs', 'Nude', '44', '4', '89,90', '35,00', '', 'Miza', 'Consignado'],
        ['Calcinha Fio Dental', 'Calcinhas', 'Vinho', 'P', '6', '29,90', '11,00', '', '', 'Comprado'],
        ['Calcinha Fio Dental', 'Calcinhas', 'Vinho', 'M', '8', '29,90', '11,00', '', '', 'Comprado'],
        ['Camisola Cetim', 'Pijamas e camisolas', 'Rosa', 'M', '1', '139,90', '60,00', '', '', ''],
    ];

    public function __construct(private GridMatcher $grid, private StockService $stock) {}

    /**
     * Planilha → prévia: produtos agrupados, o que já existe, e os erros por linha.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array{groups: array, errors: array<int, string>, missing: string[]}
     */
    public function preview(array $rows): array
    {
        $first = reset($rows) ?: [];
        $missing = array_values(array_filter(['name' => 'Produto', 'price' => 'Preço de venda'], fn ($label, $field) => ! array_intersect(self::COLUMNS[$field], array_keys($first)), ARRAY_FILTER_USE_BOTH));
        if (! array_intersect(self::COLUMNS['name'], array_keys($first))) {
            return ['groups' => [], 'errors' => [], 'missing' => $missing];
        }

        $groups = [];
        $errors = [];
        foreach ($rows as $n => $row) {
            $name = Str::limit(preg_replace('/\s+/', ' ', Sheet::pick($row, self::COLUMNS['name'])), 150, '');
            if ($name === '') {
                $errors[$n] = 'sem o nome do produto';

                continue;
            }
            $qty = Sheet::int(Sheet::pick($row, self::COLUMNS['quantity']));
            if ($qty === false || ($qty !== null && $qty < 0)) {
                $errors[$n] = 'quantidade "'.Sheet::pick($row, self::COLUMNS['quantity']).'" não é um número inteiro';

                continue;
            }
            $priceText = Sheet::pick($row, self::COLUMNS['price']);
            $price = Sheet::cents($priceText);
            if ($priceText !== '' && ($price === null || $price < 0)) {
                $errors[$n] = "preço \"{$priceText}\" não é um valor";

                continue;
            }
            $mode = Str::lower(Str::ascii(Sheet::pick($row, self::COLUMNS['mode'])));

            $key = Str::lower($name);
            $groups[$key] ??= ['name' => $name, 'category' => '', 'supplier' => '', 'mode' => null, 'lines' => []];
            $g = &$groups[$key];
            $g['category'] = $g['category'] ?: Str::limit(Sheet::pick($row, self::COLUMNS['category']), 80, '');
            $g['supplier'] = $g['supplier'] ?: Str::limit(Sheet::pick($row, self::COLUMNS['supplier']), 120, '');
            $g['mode'] ??= str_starts_with($mode, 'consig') ? 'consignado' : (str_starts_with($mode, 'compr') || str_starts_with($mode, 'propri') ? 'compra' : null);
            $g['lines'][] = [
                'row' => $n,
                'color' => Str::limit(Str::title(Str::lower(Sheet::pick($row, self::COLUMNS['color']))), 40, ''),
                'size' => Str::limit(Str::upper(Sheet::pick($row, self::COLUMNS['size'])), 20, ''),
                'quantity' => (int) $qty,
                'price_cents' => $price,
                'cost_cents' => Sheet::cents(Sheet::pick($row, self::COLUMNS['cost'])),
                'sku' => Str::limit(Str::upper(preg_replace('/\s+/', '', Sheet::pick($row, self::COLUMNS['sku']))), 60, ''),
                'barcode' => preg_replace('/\D/', '', Sheet::pick($row, self::COLUMNS['barcode'])),
                'description' => $name,
            ];
            unset($g);
        }

        foreach ($groups as $key => &$g) {
            // A mesma variação repetida em duas linhas: soma as quantidades.
            $merged = [];
            foreach ($g['lines'] as $line) {
                $k = Str::upper($line['color'].'|'.$line['size']);
                if (isset($merged[$k])) {
                    $merged[$k]['quantity'] += $line['quantity'];
                    $merged[$k]['price_cents'] ??= $line['price_cents'];
                    $merged[$k]['cost_cents'] ??= $line['cost_cents'];
                } else {
                    $merged[$k] = $line;
                }
            }
            $g['lines'] = array_values($merged);
            $existing = $this->grid->existing($g + ['lines' => $g['lines']]);
            $g['product_id'] = $existing?->id;
            $g['existing_name'] = $existing?->name;
            $g['sale_cents'] = (int) (collect($g['lines'])->pluck('price_cents')->filter()->min() ?? ($existing ? $existing->variants->min('price_cents') : 0));
            $g['cost_cents'] = (int) (collect($g['lines'])->pluck('cost_cents')->filter()->min() ?? 0);
            if (! $existing && $g['sale_cents'] <= 0) {
                $g['error'] = 'produto novo sem preço de venda';
            }
            if (count($g['lines']) > 300) {
                $g['error'] = 'mais de 300 variações';
            }
        }
        unset($g);

        return ['groups' => array_values($groups), 'errors' => $errors, 'missing' => $missing];
    }

    /**
     * Lança a prévia. $stockMode "somar" = entrada (soma ao que já tem); "igualar" = contagem (o estoque passa a
     * ser o da planilha).
     *
     * @return array{created: int, updated: int, variants: int, units: int}
     */
    public function apply(array $groups, string $stockMode, string $reason): array
    {
        return DB::transaction(function () use ($groups, $stockMode, $reason) {
            $summary = ['created' => 0, 'updated' => 0, 'variants' => 0, 'units' => 0];
            $categories = [];
            foreach ($groups as $g) {
                if (! empty($g['error'])) {
                    continue;
                }
                $categoryId = null;
                if ($g['category'] !== '') {
                    $categoryId = $categories[Str::lower($g['category'])] ??= (Category::whereRaw('lower(name) = ?', [Str::lower($g['category'])])->value('id')
                        ?? Category::create(['name' => $g['category']])->id);
                }
                $isNew = empty($g['product_id']) || ! Product::whereKey($g['product_id'])->exists();
                $product = $this->grid->productFor(['name' => $g['name'], 'category_id' => $categoryId, 'sale_cents' => $g['sale_cents'], 'cost_cents' => $g['cost_cents'],
                    'product_id' => $isNew ? null : $g['product_id'], 'lines' => $g['lines']],
                    array_filter(['brand' => $g['supplier'] ?: null, 'supplier' => $g['supplier'] ?: null, 'supply_mode' => $g['mode']]), 'planilha');
                $summary[$isNew ? 'created' : 'updated']++;
                if (! $isNew) {
                    $product->update(array_filter(['category_id' => $categoryId, 'supplier' => $g['supplier'] ?: null, 'supply_mode' => $g['mode']]));
                }

                foreach ($g['lines'] as $line) {
                    $variant = $this->grid->variantFor($product, $line, $line['price_cents'] ?: $g['sale_cents']);
                    $changes = array_filter([
                        'price_cents' => $line['price_cents'] ?: null,
                        'cost_cents' => $line['cost_cents'] ?: null,
                        'gtin' => $line['barcode'] ?: null,
                        'status' => 'active',
                    ], fn ($v) => $v !== null);
                    if ($line['sku'] !== '' && $line['sku'] !== $variant->sku && ! Variant::where('sku', $line['sku'])->whereKeyNot($variant->id)->exists()) {
                        $changes['sku'] = $line['sku'];
                    }
                    $variant->update($changes);
                    $summary['variants']++;

                    if ($stockMode === 'igualar') {
                        $current = (int) StockLevel::where('variant_id', $variant->id)->value('on_hand');
                        if ($current !== $line['quantity']) {
                            $this->stock->count($variant, $line['quantity'], $reason);
                        }
                    } elseif ($line['quantity'] > 0) {
                        $this->stock->receive($variant, $line['quantity'], $reason);
                    }
                    $summary['units'] += $line['quantity'];
                }
            }
            Audit::record('commerce.import.products', metadata: $summary + ['mode' => $stockMode]);

            return $summary;
        });
    }
}
