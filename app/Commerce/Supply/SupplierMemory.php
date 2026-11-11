<?php

namespace App\Commerce\Supply;

use App\Commerce\Catalog\GridMatcher;
use App\Commerce\Catalog\Variant;
use Illuminate\Support\Str;

/**
 * O que a loja já conferiu de cada fornecedora. Reconhece a fornecedora pelo CNPJ (ou pelo nome que vem no
 * documento) e cada peça pelo código de barras, pelo código do fornecedor ou pela descrição: a peça
 * reconhecida já vem no produto, na cor e no tamanho que a loja escolheu da última vez — inclusive quando a
 * loja corrigiu o nome, a cor ou o tamanho na conferência.
 */
class SupplierMemory
{
    public function __construct(private GridMatcher $grid) {}

    /** Fornecedora do documento: a já conhecida (CNPJ, depois nome) ou uma nova com o padrão da origem. */
    public function supplier(array $parsed): Supplier
    {
        $document = preg_replace('/\D/', '', (string) ($parsed['document'] ?? '')) ?: null;
        $documentName = Str::limit(Str::squish((string) ($parsed['supplier'] ?? '')), 120, '') ?: null;

        $supplier = ($document ? Supplier::where('document', $document)->first() : null)
            ?? ($documentName ? Supplier::where(fn ($q) => $q->whereRaw('lower(document_name) = ?', [Str::lower($documentName)])
                ->orWhereRaw('lower(name) = ?', [Str::lower($documentName)]))->first() : null);

        if ($supplier) {
            if ($document && ! $supplier->document) {
                $supplier->update(['document' => $document]);
            }

            return $supplier;
        }

        $nfe = ($parsed['source'] ?? null) === 'nfe';

        return Supplier::create([
            'name' => $documentName ?? 'Fornecedor', 'document_name' => $documentName, 'document' => $document,
            'mode' => $nfe && empty($parsed['consigned']) ? 'compra' : 'consignado',
            'price_basis' => $nfe ? 'custo' : 'venda', 'percent' => $nfe ? 100 : 30,
        ]);
    }

    /** Já recebeu alguma remessa lançada desta fornecedora? */
    public function known(Supplier $supplier): bool
    {
        return SupplyBatch::where('supplier_id', $supplier->id)->where('status', '!=', 'rascunho')->exists();
    }

    /**
     * Troca o que o leitor adivinhou pelo que a loja já conferiu: linhas reconhecidas vão para o produto
     * delas, com a cor e o tamanho da variação. As outras ficam como o leitor agrupou.
     */
    public function recall(Supplier $supplier, array $groups): array
    {
        $known = [];
        $rest = [];
        foreach ($groups as $group) {
            $left = [];
            $sibling = null;
            foreach ($group['lines'] as $line) {
                $variant = $this->variant($supplier, $line);
                if (! $variant) {
                    $left[] = $line;

                    continue;
                }
                $id = $variant->product_id;
                $sibling ??= $id;
                $known[$id] ??= ['name' => $variant->product->name, 'ref' => $group['ref'] ?? null, 'product_id' => $id, 'lines' => []];
                $known[$id]['lines'][] = ['color' => (string) ($variant->options['Cor'] ?? ''), 'size' => (string) ($variant->options['Tamanho'] ?? ''), 'known' => true] + $line;
            }
            if ($left === []) {
                continue;
            }
            // Mesma referência/nome de peças reconhecidas (ex.: uma cor nova do mesmo produto): junta com elas.
            if ($sibling) {
                array_push($known[$sibling]['lines'], ...$left);
            } else {
                $rest[] = ['lines' => $left] + $group;
            }
        }

        // O que sobrou e já existe na loja com o mesmo nome/código também junta com o produto reconhecido.
        foreach ($rest as $i => $group) {
            $product = $this->grid->existing($group);
            if ($product && isset($known[$product->id])) {
                array_push($known[$product->id]['lines'], ...$group['lines']);
                unset($rest[$i]);
            }
        }

        return [...array_values($known), ...array_values($rest)];
    }

    /** Guarda a peça conferida para a próxima remessa. */
    public function remember(Supplier $supplier, array $line, Variant $variant): void
    {
        foreach (self::codes($line) as $code) {
            SupplierCode::updateOrCreate(['supplier_id' => $supplier->id, 'code' => $code], ['variant_id' => $variant->id]);
        }
    }

    private function variant(Supplier $supplier, array $line): ?Variant
    {
        $variant = ! empty($line['barcode']) ? Variant::with('product')->where('gtin', $line['barcode'])->first() : null;
        if (! $variant) {
            foreach (self::codes($line) as $code) {
                $id = SupplierCode::where('supplier_id', $supplier->id)->where('code', $code)->value('variant_id');
                if ($id && $variant = Variant::with('product')->find($id)) {
                    break;
                }
            }
        }

        return $variant?->product && $variant->product->type !== 'kit' && $variant->product->status !== 'archived' ? $variant : null;
    }

    /**
     * Chaves da peça, da mais segura para a menos. A descrição só vale quando o documento não tem código:
     * numa nota sem tamanho na descrição, a mesma descrição serve para vários tamanhos.
     *
     * @return string[]
     */
    public static function codes(array $line): array
    {
        $codes = [];
        if (! empty($line['barcode'])) {
            $codes[] = 'bar:'.$line['barcode'];
        }
        if (! empty($line['code'])) {
            $codes[] = Str::limit('cod:'.Str::upper(trim($line['code'])), 160, '');
        }
        if ($codes === [] && ! empty($line['description'])) {
            $codes[] = Str::limit('desc:'.Str::upper(Str::ascii(Str::squish($line['description']))), 160, '');
        }

        return $codes;
    }
}
