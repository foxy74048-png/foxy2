<?php

namespace App\Commerce\Catalog;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Acha ou cria o produto e a variação certa a partir de linhas "cor + tamanho" (comprovante de remessa,
 * planilha de estoque). Grade sempre Cor e/ou Tamanho, só com o que veio; combinações que não vieram
 * ficam inativas e entram depois se aparecerem.
 *
 * Grupo: ['name', 'category_id', 'sale_cents', 'cost_cents', 'product_id'?, 'lines' => [['color', 'size', 'barcode'?, 'description'?]]]
 */
class GridMatcher
{
    public function __construct(private CatalogService $catalog) {}

    /** Produto que já existe: pelo código de barras de alguma linha, pelo SKU de alguma linha ou pelo mesmo nome. */
    public function existing(array $group): ?Product
    {
        $lines = collect($group['lines']);
        $barcodes = $lines->pluck('barcode')->filter()->all();
        $skus = $lines->pluck('sku')->filter()->map(fn ($s) => Str::upper($s))->all();
        $variant = ($barcodes ? Variant::with('product.variants')->whereIn('gtin', $barcodes)->first() : null)
            ?? ($skus ? Variant::with('product.variants')->whereIn('sku', $skus)->first() : null);

        $product = $variant?->product ?? Product::with('variants')->where('type', '!=', 'kit')->whereRaw('lower(name) = ?', [Str::lower(trim($group['name']))])->first();

        return $product && $product->type !== 'kit' ? $product : null;
    }

    /**
     * @param  array  $extra  dados do produto novo (brand, supplier, supply_mode, description)
     * @param  string  $suffix  vai no nome quando já existe um produto com o mesmo nome e outra grade
     */
    public function productFor(array $group, array $extra = [], string $suffix = ''): Product
    {
        $names = self::axes($group['lines']);
        $product = ! empty($group['product_id']) ? Product::with('variants')->find($group['product_id']) : $this->existing($group);
        // Produto existente com outra grade (ex.: sem cor) não recebe estas combinações: vira um produto novo.
        if ($product && array_values($product->option_names ?? []) !== $names) {
            $product = null;
            $group['name'] .= $suffix !== '' ? ' ('.$suffix.')' : ' (2)';
        }
        if (! $product) {
            $values = array_map(fn ($axis) => collect($group['lines'])->pluck($axis === 'Tamanho' ? 'size' : 'color')->filter()->unique(fn ($v) => Str::upper($v))->values()->all(), $names);
            $product = $this->catalog->create(['name' => Str::limit($group['name'], 150, ''), 'category_id' => $group['category_id'] ?: null,
                'price_cents' => (int) $group['sale_cents'], 'cost_cents' => (int) $group['cost_cents'] ?: null, 'status' => 'active'] + $extra, $names, $values);
            $wanted = collect($group['lines'])->map(fn ($l) => self::lineKey($names, $l))->all();
            $product->variants()->get()->each(fn (Variant $v) => in_array(self::variantKey($names, $v), $wanted, true) || $v->update(['status' => 'inactive']));
        }

        return $product->load('variants');
    }

    public function variantFor(Product $product, array $line, int $saleCents): Variant
    {
        $names = $product->option_names ?? [];
        $find = fn () => $product->variants()->get()->first(fn (Variant $v) => self::variantKey($names, $v) === self::lineKey($names, $line));
        $variant = (! empty($line['barcode']) ? Variant::where('product_id', $product->id)->where('gtin', $line['barcode'])->first() : null) ?? $find();
        if (! $variant) {
            $new = [];
            foreach ($names as $i => $name) {
                $value = $name === 'Tamanho' ? $line['size'] : $line['color'];
                $current = collect($product->variants()->get())->map(fn (Variant $v) => Str::upper($v->options[$name] ?? ''))->all();
                $new[$i] = in_array(Str::upper($value), $current, true) ? [] : [$value];
            }
            $this->catalog->extendGrid($product, $new, $saleCents);
            $variant = $find();
        }

        return $variant ?? throw ValidationException::withMessages(['lines' => 'Não deu para achar a variação de '.($line['description'] ?? $product->name).'.']);
    }

    /** @return string[] características da grade presentes nas linhas (Cor antes de Tamanho, como numa etiqueta) */
    public static function axes(array $lines): array
    {
        return array_values(array_filter([collect($lines)->pluck('color')->filter()->isNotEmpty() ? 'Cor' : null, collect($lines)->pluck('size')->filter()->isNotEmpty() ? 'Tamanho' : null]));
    }

    private static function lineKey(array $names, array $line): string
    {
        return Str::upper(implode('|', array_map(fn ($n) => trim((string) ($n === 'Tamanho' ? ($line['size'] ?? '') : ($line['color'] ?? ''))), $names)));
    }

    private static function variantKey(array $names, Variant $variant): string
    {
        return Str::upper(implode('|', array_map(fn ($n) => trim((string) ($variant->options[$n] ?? '')), $names)));
    }
}
