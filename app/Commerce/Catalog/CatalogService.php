<?php

namespace App\Commerce\Catalog;

use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Única porta para criar e mudar produtos, variantes e kits (docs/09, seção 3).
 */
class CatalogService
{
    public const MAX_AXES = 3;

    public const MAX_VARIANTS = 200;

    /**
     * Grade digitada na tela → [nomes dos eixos, valores por eixo]. Ex.: [["Tamanho","P, M, G"], ["Cor","Azul, Preto"]].
     *
     * @param  array<int, array{name?: ?string, values?: ?string}>  $axes
     * @return array{0: string[], 1: array<int, string[]>}
     */
    public static function parseGrid(array $axes): array
    {
        $names = [];
        $values = [];

        foreach (array_slice($axes, 0, self::MAX_AXES) as $axis) {
            $name = trim((string) ($axis['name'] ?? ''));
            $list = collect(preg_split('/[,;\n]/', (string) ($axis['values'] ?? '')))
                ->map(fn ($v) => trim($v))->filter()->unique(fn ($v) => Str::lower($v))->values()->all();

            if ($name === '' && $list === []) {
                continue;
            }
            if ($name === '' || $list === []) {
                throw ValidationException::withMessages(['grid' => 'Cada linha da grade precisa de um nome (ex.: Tamanho) e de valores (ex.: P, M, G).']);
            }
            if (in_array(Str::lower($name), array_map(fn ($n) => Str::lower($n), $names), true)) {
                throw ValidationException::withMessages(['grid' => "A grade tem \"{$name}\" duas vezes."]);
            }

            $names[] = $name;
            $values[] = $list;
        }

        return [$names, $values];
    }

    /**
     * Produto com as variantes da grade (ou uma variante só, sem grade).
     */
    public function create(array $data, array $optionNames, array $optionValues): Product
    {
        $combinations = self::combinations($optionNames, $optionValues);
        $this->guardSize(count($combinations));

        return DB::transaction(function () use ($data, $optionNames, $combinations) {
            $product = Product::create([
                'category_id' => $data['category_id'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'brand' => $data['brand'] ?? null,
                'status' => $data['status'] ?? 'active',
                'option_names' => $optionNames ?: null,
                'specs' => $data['specs'] ?? null,
            ]);

            $this->addVariants($product, $combinations, $data['sku'] ?? null, $data['price_cents'], $data['cost_cents'] ?? null);
            Audit::record('commerce.product.created', subject: $product, metadata: ['variants' => count($combinations)]);

            return $product;
        });
    }

    /**
     * Acrescenta valores à grade (ex.: tamanho GG) e cria só as combinações que faltam.
     */
    public function extendGrid(Product $product, array $newValuesByAxis, int $priceCents): int
    {
        $names = $product->option_names ?? [];
        if ($names === []) {
            throw ValidationException::withMessages(['grid' => 'Este produto não tem grade.']);
        }

        $existing = $product->variants()->get();
        $values = [];
        foreach ($names as $i => $name) {
            $current = $existing->map(fn (Variant $v) => $v->options[$name] ?? null)->filter()->unique()->values()->all();
            $values[$i] = collect([...$current, ...($newValuesByAxis[$i] ?? [])])->unique(fn ($v) => Str::lower($v))->values()->all();
        }

        $known = $existing->map(fn (Variant $v) => self::key($v->options ?? []))->all();
        $missing = array_values(array_filter(self::combinations($names, $values), fn ($c) => ! in_array(self::key($c), $known, true)));
        $this->guardSize($existing->count() + count($missing));

        DB::transaction(function () use ($product, $missing, $priceCents, $existing) {
            $this->addVariants($product, $missing, null, $priceCents, null, (int) $existing->max('position') + 1);
            Audit::record('commerce.product.grid_extended', subject: $product, metadata: ['added' => count($missing)]);
        });

        return count($missing);
    }

    /**
     * Edição das variantes em lote (SKU, preço, custo, GTIN, ativa). Mudança de preço fica na auditoria.
     *
     * @param  array<string, array{sku: string, price_cents: int, cost_cents: ?int, gtin: ?string, status: string}>  $rows
     */
    public function updateVariants(Product $product, array $rows): void
    {
        $variants = $product->variants()->get()->keyBy('id');
        $skus = collect($rows)->pluck('sku')->map(fn ($s) => Str::upper($s));
        if ($skus->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['variants' => 'Há SKU repetido: '.$skus->duplicates()->first().'.']);
        }

        DB::transaction(function () use ($variants, $rows) {
            foreach ($rows as $id => $row) {
                $variant = $variants->get($id);
                if (! $variant) {
                    continue;
                }

                $sku = Str::upper(trim($row['sku']));
                if ($sku !== $variant->sku && Variant::where('sku', $sku)->whereKeyNot($variant->id)->exists()) {
                    throw ValidationException::withMessages(['variants' => "O SKU {$sku} já é usado em outro produto da loja."]);
                }

                $oldPrice = $variant->price_cents;
                $variant->update([
                    'sku' => $sku,
                    'price_cents' => $row['price_cents'],
                    'cost_cents' => $row['cost_cents'],
                    'gtin' => $row['gtin'] ?: null,
                    'status' => $row['status'],
                ]);

                if ($oldPrice !== $variant->price_cents) {
                    Audit::record('commerce.variant.price_changed', subject: $variant, metadata: ['from' => $oldPrice, 'to' => $variant->price_cents]);
                }
            }
        });
    }

    /**
     * Kit: produto com uma variante feita de outras (ex.: camiseta + boné). Peças: [variant_id => quantidade].
     */
    public function createKit(array $data, array $components): Product
    {
        return DB::transaction(function () use ($data, $components) {
            $product = Product::create([
                'type' => 'kit',
                'category_id' => $data['category_id'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'brand' => $data['brand'] ?? null,
                'status' => $data['status'] ?? 'active',
                'specs' => $data['specs'] ?? null,
            ]);
            $variant = $this->addVariants($product, [[]], $data['sku'] ?? null, $data['price_cents'], null, 0, true)[0];
            $this->syncComponents($variant, $components);
            Audit::record('commerce.kit.created', subject: $product, metadata: ['components' => count($components)]);

            return $product;
        });
    }

    /** Combinações demais viram uma lista impossível de usar (e de anunciar). */
    public const MAX_SET_COMBINATIONS = 300;

    /**
     * Conjunto: kit em que a cliente escolhe o tamanho de cada peça (ex.: sutiã M com calcinha G). Cada
     * combinação vira uma variante-kit com as suas peças, então estoque, loja e canais funcionam como num kit.
     *
     * @param  array<int, array{product_id: string, label: string, quantity?: int}>  $pieces
     * @param  ?string  $same  característica que precisa ser igual nas peças (ex.: "Cor"); null = combina tudo
     */
    public function createSet(array $data, array $pieces, ?string $same): Product
    {
        if (count($pieces) < 2) {
            throw ValidationException::withMessages(['set' => 'O conjunto precisa de pelo menos duas peças (ex.: sutiã e calcinha).']);
        }

        return DB::transaction(function () use ($data, $pieces, $same) {
            $labels = array_map(fn ($p) => Str::limit(trim($p['label']), 30, ''), $pieces);
            $product = Product::create([
                'type' => 'kit',
                'category_id' => $data['category_id'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'brand' => $data['brand'] ?? null,
                'status' => $data['status'] ?? 'active',
                'specs' => $data['specs'] ?? null,
                'option_names' => array_values(array_filter([$same ?: null, ...$labels])),
                'kit_set' => [
                    'same' => $same ?: null,
                    'pieces' => array_map(fn ($p, $label) => ['product_id' => $p['product_id'], 'label' => $label, 'quantity' => max(1, min(99, (int) ($p['quantity'] ?? 1)))], $pieces, $labels),
                ],
            ]);

            $added = $this->syncSet($product, $data['price_cents'], $data['sku'] ?? null);
            if ($added === 0) {
                throw ValidationException::withMessages(['set' => 'Nenhuma combinação possível: confira se as peças têm variantes ativas'.($same ? " e a mesma \"{$same}\" em comum" : '').'.']);
            }
            Audit::record('commerce.set.created', subject: $product, metadata: ['combinations' => $added]);

            return $product;
        });
    }

    /**
     * Cria as combinações que faltam no conjunto (ex.: depois de cadastrar um tamanho novo no sutiã).
     * Não apaga nenhuma: combinação que não deve ser vendida se desativa na lista de variantes.
     */
    public function syncSet(Product $product, ?int $priceCents = null, ?string $skuBase = null): int
    {
        $config = $product->kit_set;
        $same = $config['same'] ?? null;
        $pieces = collect($config['pieces'] ?? [])->map(function ($piece) use ($same) {
            $variants = Variant::where('product_id', $piece['product_id'])->where('is_kit', false)->where('status', 'active')->orderBy('position')->get();
            if ($variants->isEmpty()) {
                throw ValidationException::withMessages(['set' => 'Uma das peças do conjunto não tem variantes ativas (ou não é um produto simples).']);
            }

            return $piece + ['variants' => $variants, 'has_same' => $same !== null && $variants->contains(fn ($v) => isset($v->options[$same]))];
        });

        // Valores da característica igual que existem em todas as peças que a têm.
        $groups = [null];
        if ($same !== null && $pieces->contains('has_same', true)) {
            $groups = $pieces->where('has_same', true)
                ->map(fn ($p) => $p['variants']->pluck("options.{$same}")->filter()->unique()->values()->all())
                ->reduce(fn ($carry, $values) => $carry === null ? $values : array_values(array_intersect($carry, $values)));
        }

        $combinations = [];
        foreach ($groups as $value) {
            $lists = $pieces->map(fn ($p) => $value === null || ! $p['has_same'] ? $p['variants'] : $p['variants']->filter(fn ($v) => ($v->options[$same] ?? null) === $value));
            $result = [[]];
            foreach ($lists as $i => $list) {
                $next = [];
                foreach ($result as $partial) {
                    foreach ($list as $variant) {
                        $next[] = $partial + [$i => $variant];
                    }
                }
                $result = $next;
            }
            foreach ($result as $combo) {
                if (count($combo) === $pieces->count()) {
                    $combinations[] = ['same' => $value, 'pieces' => $combo];
                }
            }
        }

        if (count($combinations) > self::MAX_SET_COMBINATIONS) {
            throw ValidationException::withMessages(['set' => 'Esse conjunto daria '.count($combinations).' combinações (máximo '.self::MAX_SET_COMBINATIONS.'). Marque a característica que precisa ser igual (ex.: Cor) ou use menos peças.']);
        }

        // Combinações que já existem (pelas peças de cada variante-kit).
        $existing = $product->variants()->with('components')->get()
            ->map(fn (Variant $v) => $v->components->pluck('component_variant_id')->sort()->implode('|'))->flip();
        $price = $priceCents ?? (int) $product->variants()->min('price_cents');
        $position = (int) $product->variants()->max('position') + 1;
        $taken = [];
        $added = 0;

        foreach ($combinations as $combo) {
            $key = collect($combo['pieces'])->pluck('id')->sort()->implode('|');
            if ($existing->has($key)) {
                continue;
            }

            $options = $combo['same'] !== null ? [$same => $combo['same']] : [];
            $skuParts = $combo['same'] !== null ? [$combo['same']] : [];
            $components = [];
            foreach ($combo['pieces'] as $i => $variant) {
                $size = implode(' / ', array_values(array_diff_key($variant->options ?? [], $same ? [$same => true] : []))) ?: 'único';
                $label = $pieces[$i]['label'];
                $options[$label] = $label.' '.$size;
                $skuParts[] = $size;
                $components[$variant->id] = ($components[$variant->id] ?? 0) + $pieces[$i]['quantity'];
            }

            $sku = self::suggestSku($skuBase ?: $product->name, $skuParts, $taken);
            $taken[] = $sku;
            $kitVariant = Variant::create([
                'product_id' => $product->id, 'sku' => $sku, 'options' => $options, 'price_cents' => $price,
                'cost_cents' => null, 'is_kit' => true, 'position' => $position++,
            ]);
            $this->syncComponents($kitVariant, $components);
            $existing->put($key, true);
            $added++;
        }

        return $added;
    }

    /**
     * @param  array<string, int>  $components  variant_id => quantidade
     */
    public function syncComponents(Variant $kit, array $components): void
    {
        $components = array_filter($components, fn ($q) => (int) $q > 0);
        if ($components === []) {
            throw ValidationException::withMessages(['components' => 'O kit precisa de pelo menos uma peça.']);
        }

        $found = Variant::whereIn('id', array_keys($components))->get()->keyBy('id');
        foreach (array_keys($components) as $id) {
            $piece = $found->get($id);
            if (! $piece) {
                throw ValidationException::withMessages(['components' => 'Uma das peças não existe nesta loja.']);
            }
            if ($piece->is_kit || $piece->id === $kit->id) {
                throw ValidationException::withMessages(['components' => "{$piece->sku} é um kit: kit não pode ter outro kit dentro."]);
            }
        }

        DB::transaction(function () use ($kit, $components) {
            KitComponent::where('kit_variant_id', $kit->id)->whereNotIn('component_variant_id', array_keys($components))->delete();
            foreach ($components as $id => $quantity) {
                KitComponent::updateOrCreate(['kit_variant_id' => $kit->id, 'component_variant_id' => $id], ['quantity' => min((int) $quantity, 999)]);
            }
        });
    }

    /** SKU livre na loja, a partir de uma base e dos valores da grade ("CAMISETA-BASICA-P-AZUL"). */
    public static function suggestSku(string $base, array $options, array $taken = []): string
    {
        $parts = [Str::limit(Str::upper(Str::slug($base)), 24, '') ?: 'PROD'];
        foreach ($options as $value) {
            $parts[] = Str::limit(Str::upper(Str::slug($value)), 10, '');
        }

        $sku = Str::limit(implode('-', array_filter($parts)), 55, '');
        $candidate = $sku;
        for ($i = 2; in_array($candidate, $taken, true) || Variant::where('sku', $candidate)->exists(); $i++) {
            $candidate = $sku.'-'.$i;
        }

        return $candidate;
    }

    /** @return Variant[] */
    private function addVariants(Product $product, array $combinations, ?string $skuBase, int $priceCents, ?int $costCents, int $position = 0, bool $isKit = false): array
    {
        $taken = [];
        $created = [];

        foreach ($combinations as $options) {
            $sku = self::suggestSku($skuBase ?: $product->name, array_values($options), $taken);
            $taken[] = $sku;
            $created[] = Variant::create([
                'product_id' => $product->id,
                'sku' => $sku,
                'options' => $options ?: null,
                'price_cents' => $priceCents,
                'cost_cents' => $costCents,
                'is_kit' => $isKit,
                'position' => $position++,
            ]);
        }

        return $created;
    }

    /** Todas as combinações da grade, na ordem digitada. Sem grade: uma combinação vazia. */
    private static function combinations(array $names, array $values): array
    {
        $result = [[]];
        foreach ($names as $i => $name) {
            $next = [];
            foreach ($result as $partial) {
                foreach ($values[$i] as $value) {
                    $next[] = $partial + [$name => $value];
                }
            }
            $result = $next;
        }

        return $result;
    }

    private static function key(array $options): string
    {
        return Str::lower(json_encode(array_values($options)));
    }

    private function guardSize(int $count): void
    {
        if ($count > self::MAX_VARIANTS) {
            throw ValidationException::withMessages(['grid' => 'A grade geraria '.$count.' variantes; o máximo por produto é '.self::MAX_VARIANTS.'.']);
        }
    }
}
