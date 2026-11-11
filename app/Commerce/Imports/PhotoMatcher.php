<?php

namespace App\Commerce\Imports;

use App\Commerce\Catalog\Product;
use App\Commerce\Catalog\Variant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Fotos em lote: o nome do arquivo diz de qual produto (e cor) é a foto.
 * "sutia-renda-floral-preto-2.jpg" → Sutiã Renda Floral, cor Preto · "SUT-REN-44-PRETO.jpg" → pelo SKU ·
 * "7891234567890.jpg" → pelo código de barras · "calcinha fio dental.png" → o produto, foto para todas as cores.
 */
class PhotoMatcher
{
    /** @var Collection<int, Product>|null */
    private ?Collection $products = null;

    /**
     * @return array{product: Product, option_name: ?string, option_value: ?string}|null
     */
    public function match(string $filename): ?array
    {
        $base = trim(pathinfo($filename, PATHINFO_FILENAME));
        // Contador no fim ("-2", " (3)", "_01") não faz parte do nome. Tenta primeiro com ele (pode ser tamanho ou SKU).
        $stripped = trim(preg_replace('/[\s_\-]*(\(\d{1,3}\)|\d{1,2})$/', '', $base));
        foreach (array_unique([$base, $stripped]) as $candidate) {
            if ($candidate !== '' && ($found = $this->byCode($candidate) ?? $this->byName($candidate))) {
                return $found;
            }
        }

        return null;
    }

    private function byCode(string $base): ?array
    {
        $digits = preg_replace('/\D/', '', $base);
        $variant = Variant::with('product')->where('sku', Str::upper(str_replace(' ', '', $base)))->first()
            ?? (strlen($digits) >= 8 && $digits === $base ? Variant::with('product')->where('gtin', $digits)->first() : null);
        if (! $variant?->product || $variant->product->type === 'kit') {
            return null;
        }
        $color = $this->colorAxis($variant->product);

        return ['product' => $variant->product, 'option_name' => $color && isset($variant->options[$color]) ? $color : null, 'option_value' => $color ? ($variant->options[$color] ?? null) : null];
    }

    private function byName(string $base): ?array
    {
        $slug = Str::slug($base);
        $best = null;
        foreach ($this->products() as $product) {
            $name = Str::slug($product->name);
            if ($name !== '' && ($slug === $name || str_starts_with($slug, $name.'-')) && (! $best || strlen($name) > strlen(Str::slug($best->name)))) {
                $best = $product;
            }
        }
        if (! $best) {
            return null;
        }
        $rest = trim(substr($slug, strlen(Str::slug($best->name))), '-');
        $axis = $this->colorAxis($best);
        if ($axis && $rest !== '') {
            foreach (collect($best->imageOptions()[$axis] ?? [])->sortByDesc(fn ($v) => strlen($v)) as $value) {
                $v = Str::slug($value);
                if ($rest === $v || str_starts_with($rest, $v.'-')) {
                    return ['product' => $best, 'option_name' => $axis, 'option_value' => $value];
                }
            }
        }

        return ['product' => $best, 'option_name' => null, 'option_value' => null];
    }

    /** Eixo em que as fotos mudam (Cor, Estampa...), se o produto tiver. */
    private function colorAxis(Product $product): ?string
    {
        foreach ($product->option_names ?? [] as $name) {
            if (preg_match('/^(cor|cores|estampa|color|colour)$/i', Str::ascii(trim($name)))) {
                return $name;
            }
        }

        return null;
    }

    private function products(): Collection
    {
        return $this->products ??= Product::with('variants')->where('type', '!=', 'kit')->where('status', '!=', 'archived')->get();
    }
}
