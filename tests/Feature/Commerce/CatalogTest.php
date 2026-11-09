<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Catalog\Category;
use App\Commerce\Catalog\KitComponent;
use App\Commerce\Catalog\Money;
use App\Commerce\Catalog\Product;
use App\Commerce\Catalog\ProductImage;
use App\Commerce\Catalog\Variant;
use App\Commerce\Inventory\StockLevel;
use App\Commerce\Inventory\StockService;
use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Models\User;
use App\Platform\Audit\AuditEntry;
use App\Platform\Billing\Subscription;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Files\File;
use App\Platform\Memberships\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Foxy Commerce, fatia 3 (docs/09): catálogo com grade de tamanho/cor, kits, fotos e categorias.
 */
class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['email' => 'ana@loja.test']);
        $this->store = app(OpenStore::class)->open($this->owner, 'Moda da Ana');
    }

    private function url(string $path): string
    {
        return 'http://'.config('foxy.hosts.commerce').$path;
    }

    /** Lê dados da loja como o app faz (dentro do contexto dela). */
    private function inStore(callable $callback, ?Store $store = null): mixed
    {
        $organization = ($store ?? $this->store)->organization;

        return app(ContextStore::class)->run(ExecutionContext::forUser($organization, $this->owner->id), $callback);
    }

    private function createShirt(array $extra = [])
    {
        return $this->actingAs($this->owner)->post($this->url('/app/produtos'), array_merge([
            'type' => 'simple', 'name' => 'Camiseta Básica', 'status' => 'active', 'price' => '79,90', 'cost' => '35',
            'grid' => [['name' => 'Tamanho', 'values' => 'P, M, G'], ['name' => 'Cor', 'values' => 'Azul, Preto']],
        ], $extra));
    }

    private function shirt(): Product
    {
        return $this->inStore(fn () => Product::where('name', 'Camiseta Básica')->with('variants')->firstOrFail());
    }

    public function test_paginacao_em_portugues_e_sem_icone_gigante(): void
    {
        $html = (string) (new LengthAwarePaginator(range(1, 30), 526, 30, 2, ['path' => '/app/produtos']))->links();

        $this->assertStringContainsString('Mostrando 31–60 de 526', $html);
        $this->assertStringContainsString('Próxima', $html);
        $this->assertStringContainsString('aria-current="page">2<', $html);
        $this->assertStringContainsString('/app/produtos?page=18', $html);
        $this->assertStringNotContainsString('<svg', $html);
    }

    public function test_dinheiro_aceita_o_jeito_brasileiro_de_digitar(): void
    {
        $this->assertSame(7990, Money::toCents('79,90'));
        $this->assertSame(123456, Money::toCents('1.234,56'));
        $this->assertSame(7990, Money::toCents('79.90'));
        $this->assertSame(7900, Money::toCents('R$ 79'));
        $this->assertNull(Money::toCents('abc'));
        $this->assertSame('R$ 1.234,56', Money::format(123456));
    }

    public function test_grade_cria_uma_variante_por_combinacao_com_sku_unico(): void
    {
        $this->createShirt()->assertSessionHasNoErrors();
        $product = $this->shirt();

        $this->assertSame(['Tamanho', 'Cor'], $product->option_names);
        $this->assertCount(6, $product->variants);
        $this->assertSame(['Tamanho' => 'P', 'Cor' => 'Azul'], $product->variants[0]->options);
        $this->assertSame('CAMISETA-BASICA-P-AZUL', $product->variants[0]->sku);
        $this->assertSame(7990, $product->variants[0]->price_cents);
        $this->assertSame(3500, $product->variants[0]->cost_cents);
        $this->assertSame(6, $product->variants->pluck('sku')->unique()->count());

        // Mesmo nome de novo: os SKUs não repetem.
        $this->createShirt()->assertSessionHasNoErrors();
        $this->assertSame(12, $this->inStore(fn () => Variant::count()));
        $this->assertTrue($this->inStore(fn () => Variant::where('sku', 'CAMISETA-BASICA-P-AZUL-2')->exists()));
    }

    public function test_produto_sem_grade_tem_uma_variante(): void
    {
        $this->actingAs($this->owner)->post($this->url('/app/produtos'), ['type' => 'simple', 'name' => 'Boné Aba Reta', 'status' => 'active', 'price' => '49,90', 'sku' => 'bone-01'])
            ->assertSessionHasNoErrors();

        $variant = $this->inStore(fn () => Variant::sole());
        $this->assertSame('BONE-01', $variant->sku);
        $this->assertNull($variant->options);
    }

    public function test_grade_invalida_e_recusada(): void
    {
        $this->createShirt(['grid' => [['name' => 'Tamanho', 'values' => '']]])->assertSessionHasErrors('grid');
        $this->createShirt(['grid' => [['name' => 'Cor', 'values' => 'A'], ['name' => 'cor', 'values' => 'B']]])->assertSessionHasErrors('grid');
        $this->createShirt(['price' => 'abc'])->assertSessionHasErrors('price');
        $this->assertSame(0, $this->inStore(fn () => Product::count()));
    }

    public function test_editar_variantes_e_acrescentar_tamanho(): void
    {
        $this->createShirt();
        $product = $this->shirt();
        $rows = $product->variants->mapWithKeys(fn ($v) => [$v->id => ['sku' => $v->sku, 'price' => '79,90', 'cost' => '', 'gtin' => '', 'status' => 'active']])->all();
        $first = $product->variants[0];
        $rows[$first->id] = ['sku' => 'cam-p-az', 'price' => '89,90', 'cost' => '40,00', 'gtin' => '7891234567895', 'status' => 'inactive'];

        $this->post($this->url("/app/produtos/{$product->id}/variantes"), ['variants' => $rows])->assertSessionHasNoErrors();
        $first->refresh();
        $this->assertSame('CAM-P-AZ', $first->sku);
        $this->assertSame(8990, $first->price_cents);
        $this->assertSame('inactive', $first->status);
        $this->assertTrue(AuditEntry::withoutGlobalScopes()->where('action', 'commerce.variant.price_changed')->where('subject_id', $first->id)->exists());

        // SKU repetido é recusado.
        $second = $product->variants[1];
        $rows[$second->id]['sku'] = 'CAM-P-AZ';
        $this->post($this->url("/app/produtos/{$product->id}/variantes"), ['variants' => $rows])->assertSessionHasErrors('variants');

        // Tamanho GG: só as 2 combinações novas (GG Azul, GG Preto).
        $this->post($this->url("/app/produtos/{$product->id}/grade"), ['values' => ['GG', ''], 'price' => '84,90'])->assertSessionHasNoErrors();
        $product = $this->shirt();
        $this->assertCount(8, $product->variants);
        $this->assertSame(8490, $product->variants->firstWhere('options', ['Tamanho' => 'GG', 'Cor' => 'Azul'])->price_cents);

        // Repetir não duplica.
        $this->post($this->url("/app/produtos/{$product->id}/grade"), ['values' => ['gg', '']])->assertSessionHas('status');
        $this->assertCount(8, $this->shirt()->variants);
    }

    public function test_conjunto_com_tamanho_de_cada_peca_e_mesma_cor(): void
    {
        $this->actingAs($this->owner);
        $this->post($this->url('/app/produtos'), ['type' => 'simple', 'name' => 'Sutiã Renda', 'status' => 'active', 'price' => '89,90', 'sku' => 'SUT',
            'grid' => [['name' => 'Tamanho', 'values' => 'P, M, G'], ['name' => 'Cor', 'values' => 'Preto, Vermelho']]])->assertSessionHasNoErrors();
        $this->post($this->url('/app/produtos'), ['type' => 'simple', 'name' => 'Calcinha Renda', 'status' => 'active', 'price' => '49,90', 'sku' => 'CAL',
            'grid' => [['name' => 'Tamanho', 'values' => 'P, M, G, GG'], ['name' => 'Cor', 'values' => 'Preto, Branco']]])->assertSessionHasNoErrors();
        [$sutia, $calcinha] = $this->inStore(fn () => [Product::where('name', 'Sutiã Renda')->sole(), Product::where('name', 'Calcinha Renda')->sole()]);

        // Uma peça só não é conjunto.
        $this->post($this->url('/app/produtos'), ['type' => 'kit', 'kit_mode' => 'conjunto', 'name' => 'X', 'status' => 'active', 'price' => '10',
            'set' => ['pieces' => [['product_id' => $sutia->id, 'label' => 'Sutiã']]]])->assertSessionHasErrors('set');

        $this->post($this->url('/app/produtos'), ['type' => 'kit', 'kit_mode' => 'conjunto', 'name' => 'Conjunto Renda', 'status' => 'active', 'price' => '119,90', 'sku' => 'CONJ',
            'set' => ['match' => '1', 'same' => 'Cor', 'pieces' => [['product_id' => $sutia->id, 'label' => 'Sutiã'], ['product_id' => $calcinha->id, 'label' => 'Calcinha'], ['product_id' => '']]]])
            ->assertSessionHasNoErrors();

        $set = $this->inStore(fn () => Product::where('name', 'Conjunto Renda')->with('variants.components.component')->sole());
        $this->assertTrue($set->isSet());
        $this->assertSame(['Cor', 'Sutiã', 'Calcinha'], $set->option_names);
        $this->assertCount(12, $set->variants); // só Preto existe nas duas: 3 sutiãs × 4 calcinhas

        $mg = $set->variants->first(fn ($v) => $v->options === ['Cor' => 'Preto', 'Sutiã' => 'Sutiã M', 'Calcinha' => 'Calcinha G']);
        $this->assertNotNull($mg);
        $this->assertSame(11990, $mg->price_cents);
        $this->assertEqualsCanonicalizing(['SUT-M-PRETO', 'CAL-G-PRETO'], $mg->components->map(fn ($c) => $c->component->sku)->all());

        // Vender o conjunto baixa cada peça certa.
        $this->inStore(function () use ($mg) {
            $stock = app(StockService::class);
            $stock->receive(Variant::where('sku', 'SUT-M-PRETO')->sole(), 5);
            $stock->receive(Variant::where('sku', 'CAL-G-PRETO')->sole(), 2);
            $this->assertSame(2, $stock->availability(collect([$mg]))[$mg->id]);
            $stock->sell($mg, 1);
            $this->assertSame(4, StockLevel::where('variant_id', Variant::where('sku', 'SUT-M-PRETO')->value('id'))->value('on_hand'));
        });

        // Calcinha ganhou o vermelho: o botão cria só as combinações que faltam.
        $this->post($this->url("/app/produtos/{$calcinha->id}/grade"), ['values' => ['', 'Vermelho']])->assertSessionHasNoErrors();
        $this->post($this->url("/app/produtos/{$set->id}/conjunto"))->assertSessionHasNoErrors();
        $this->assertSame(24, $this->inStore(fn () => $set->variants()->count()));
        $this->post($this->url("/app/produtos/{$set->id}/conjunto"))->assertSessionHas('status', 'Nenhuma combinação nova: o conjunto já tem todas.');

        $this->get($this->url("/app/produtos/{$set->id}"))->assertOk()->assertSee('Peças do conjunto')->assertSee('Criar combinações que faltam')->assertDontSee('Acrescentar à grade');
        $this->get($this->url('/app/produtos/novo?tipo=kit'))->assertOk()->assertSee('Conjunto com tamanho de cada peça')->assertSee('Calcinha Renda');
    }

    public function test_kit_e_feito_de_variantes_e_nao_aceita_kit_dentro(): void
    {
        $this->createShirt();
        $this->actingAs($this->owner)->post($this->url('/app/produtos'), ['type' => 'simple', 'name' => 'Boné', 'status' => 'active', 'price' => '49,90', 'sku' => 'BONE']);

        $this->post($this->url('/app/produtos'), [
            'type' => 'kit', 'name' => 'Kit Verão', 'status' => 'active', 'price' => '119,90', 'sku' => 'KIT-VERAO',
            'components' => [['sku' => 'camiseta-basica-m-azul', 'quantity' => 2], ['sku' => 'BONE', 'quantity' => 1], ['sku' => '', 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $kit = $this->inStore(fn () => Product::where('type', 'kit')->with('variants.components.component')->sole());
        $variant = $kit->variants->sole();
        $this->assertTrue($variant->is_kit);
        $this->assertSame('KIT-VERAO', $variant->sku);
        $this->assertSame(['BONE' => 1, 'CAMISETA-BASICA-M-AZUL' => 2], $variant->components->mapWithKeys(fn ($c) => [$c->component->sku => $c->quantity])->sortKeys()->all());

        // Kit dentro de kit, SKU inexistente e kit vazio são recusados.
        $this->post($this->url("/app/produtos/{$kit->id}/kit"), ['components' => [['sku' => 'KIT-VERAO', 'quantity' => 1]]])->assertSessionHasErrors('components');
        $this->post($this->url("/app/produtos/{$kit->id}/kit"), ['components' => [['sku' => 'NAO-EXISTE', 'quantity' => 1]]])->assertSessionHasErrors('components');
        $this->post($this->url("/app/produtos/{$kit->id}/kit"), ['components' => [['sku' => '', 'quantity' => 1]]])->assertSessionHasErrors('components');

        // Trocar as peças.
        $this->post($this->url("/app/produtos/{$kit->id}/kit"), ['components' => [['sku' => 'BONE', 'quantity' => 3]]])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->inStore(fn () => KitComponent::count()));

        $this->get($this->url("/app/produtos/{$kit->id}"))->assertOk()->assertSee('Peças do kit');
        $this->get($this->url('/app/produtos'))->assertOk()->assertSee('Kit Verão')->assertSee('kit');
    }

    public function test_fotos_do_produto(): void
    {
        Storage::fake('public');
        $this->createShirt();
        $product = $this->shirt();

        $this->post($this->url("/app/produtos/{$product->id}/fotos"), ['images' => [UploadedFile::fake()->image('frente.jpg'), UploadedFile::fake()->image('costas.png')]])
            ->assertSessionHasNoErrors();

        $images = $this->inStore(fn () => ProductImage::with('file')->orderBy('position')->get());
        $this->assertCount(2, $images);
        $path = $images[0]->file->path;
        $this->assertStringStartsWith("org/{$this->store->organization_id}/commerce/products/", $path);
        Storage::disk('public')->assertExists($path);

        $this->post($this->url("/app/produtos/{$product->id}/fotos"), ['images' => [UploadedFile::fake()->create('virus.exe', 10)]])->assertSessionHasErrors('images.0');

        $this->post($this->url("/app/produtos/{$product->id}/fotos/{$images[0]->id}/apagar"))->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($path);
        $this->assertSame(1, $this->inStore(fn () => File::count()));
    }

    public function test_foto_por_cor_capa_e_aviso_de_sem_foto(): void
    {
        Storage::fake('public');
        $this->createShirt(); // Tamanho P, M, G × Cor Azul, Preto
        $product = $this->shirt();

        $this->get($this->url('/app/produtos'))->assertSee('sem foto');
        $this->get($this->url('/app/produtos?foto=sem'))->assertSee('Camiseta Básica');
        $this->get($this->url("/app/produtos/{$product->id}"))->assertSee('ainda não tem foto')->assertSee('Cor: Preto');

        $this->post($this->url("/app/produtos/{$product->id}/fotos"), ['images' => [UploadedFile::fake()->image('geral.jpg')]])->assertSessionHasNoErrors();
        $this->post($this->url("/app/produtos/{$product->id}/fotos"), ['images' => [UploadedFile::fake()->image('preta.jpg')], 'option' => 'Cor=Preto'])
            ->assertSessionHas('status', 'Fotos enviadas para Cor: Preto.');
        $this->post($this->url("/app/produtos/{$product->id}/fotos"), ['images' => [UploadedFile::fake()->image('x.jpg')], 'option' => 'Cor=Vermelho'])->assertSessionHasErrors('option');

        $images = $this->inStore(fn () => ProductImage::orderBy('position')->get());
        $this->assertNull($images[0]->option_name);
        $this->assertSame(['Cor', 'Preto'], [$images[1]->option_name, $images[1]->option_value]);

        // Variação preta: a foto dela primeiro, depois a geral. Azul: só a geral.
        $loaded = $this->inStore(fn () => Product::with('images', 'variants')->find($product->id));
        $this->assertSame([$images[1]->id, $images[0]->id], $loaded->imagesFor(['Tamanho' => 'M', 'Cor' => 'Preto'])->pluck('id')->all());
        $this->assertSame([$images[0]->id], $loaded->imagesFor(['Tamanho' => 'M', 'Cor' => 'Azul'])->pluck('id')->all());

        // Remarcar a foto, voltar para todas, e trocar a capa.
        $this->post($this->url("/app/produtos/{$product->id}/fotos/{$images[0]->id}/variacao"), ['option' => 'Cor=Azul'])->assertSessionHasNoErrors();
        $this->assertSame('Azul', $images[0]->fresh()->option_value);
        $this->post($this->url("/app/produtos/{$product->id}/fotos/{$images[0]->id}/variacao"), ['option' => ''])->assertSessionHasNoErrors();
        $this->assertNull($images[0]->fresh()->option_name);
        $this->post($this->url("/app/produtos/{$product->id}/fotos/{$images[1]->id}/capa"))->assertSessionHasNoErrors();
        $this->assertSame([0, 1], [$images[1]->fresh()->position, $images[0]->fresh()->position]);

        $this->get($this->url('/app/produtos'))->assertDontSee('sem foto</span>', false);
        $this->get($this->url("/app/produtos/{$product->id}"))->assertSee('Usar como capa')->assertSee('todas as variações');
    }

    public function test_produto_de_outra_loja_nao_abre(): void
    {
        $this->createShirt();
        $product = $this->shirt();

        $bia = User::factory()->create();
        app(OpenStore::class)->open($bia, 'Loja da Bia');

        $this->actingAs($bia)->get($this->url("/app/produtos/{$product->id}"))->assertNotFound();
        $this->actingAs($bia)->post($this->url("/app/produtos/{$product->id}/arquivar"))->assertNotFound();
        $this->actingAs($bia)->get($this->url('/app/produtos'))->assertOk()->assertDontSee('Camiseta Básica');
        $this->assertSame('active', $product->fresh()->status);
    }

    public function test_atendente_ve_o_catalogo_mas_nao_altera(): void
    {
        $this->createShirt();
        $attendant = User::factory()->create();
        Membership::create(['organization_id' => $this->store->organization_id, 'user_id' => $attendant->id, 'role' => 'attendant']);

        $this->actingAs($attendant)->get($this->url('/app/produtos'))->assertOk()->assertSee('Camiseta Básica')->assertDontSee('Novo produto');
        $this->actingAs($attendant)->get($this->url("/app/produtos/{$this->shirt()->id}"))->assertForbidden();
        $this->actingAs($attendant)->post($this->url('/app/produtos'), ['name' => 'X', 'price' => '1'])->assertForbidden();
    }

    public function test_arquivar_esconde_e_limite_de_produtos_do_plano(): void
    {
        config(['commerce.trial_limits.products' => 1]);
        $this->createShirt()->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post($this->url('/app/produtos'), ['type' => 'simple', 'name' => 'Boné', 'status' => 'active', 'price' => '10'])
            ->assertSessionHasErrors('name');

        $product = $this->shirt();
        $this->post($this->url("/app/produtos/{$product->id}/arquivar"))->assertSessionHasNoErrors();
        $this->get($this->url('/app/produtos'))->assertDontSee('Camiseta Básica');
        $this->get($this->url('/app/produtos?situacao=archived'))->assertSee('Camiseta Básica');

        // Arquivado não conta no limite; editar não o reativa.
        $this->post($this->url('/app/produtos'), ['type' => 'simple', 'name' => 'Boné', 'status' => 'active', 'price' => '10'])->assertSessionHasNoErrors();
        $this->post($this->url("/app/produtos/{$product->id}"), ['name' => 'Camiseta Básica Nova', 'status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('archived', $product->fresh()->status);
        $this->assertSame('Camiseta Básica Nova', $product->fresh()->name);
    }

    public function test_categorias_com_um_nivel_e_sem_apagar_em_uso(): void
    {
        $this->actingAs($this->owner)->post($this->url('/app/categorias'), ['name' => 'Roupas'])->assertSessionHasNoErrors();
        $roupas = $this->inStore(fn () => Category::sole());
        $this->post($this->url('/app/categorias'), ['name' => 'Camisetas', 'parent_id' => $roupas->id])->assertSessionHasNoErrors();
        $camisetas = $this->inStore(fn () => Category::where('name', 'Camisetas')->sole());

        // Neta não: a mãe precisa ser de primeiro nível.
        $this->post($this->url('/app/categorias'), ['name' => 'Manga longa', 'parent_id' => $camisetas->id])->assertSessionHasErrors('parent_id');

        $this->createShirt(['category_id' => $camisetas->id])->assertSessionHasNoErrors();
        $this->get($this->url('/app/produtos'))->assertSee('Roupas › Camisetas');
        $this->post($this->url("/app/categorias/{$camisetas->id}/apagar"))->assertSessionHasErrors('name');

        // Categoria de outra loja não serve.
        $bia = User::factory()->create();
        $lojaB = app(OpenStore::class)->open($bia, 'Loja da Bia');
        $alheia = $this->inStore(fn () => Category::create(['name' => 'Da Bia']), $lojaB);
        $this->createShirt(['name' => 'Outra', 'category_id' => $alheia->id])->assertSessionHasErrors('category_id');
    }

    public function test_so_leitura_nao_cria_produto(): void
    {
        Subscription::where('organization_id', $this->store->organization_id)->update(['trial_ends_at' => now()->subDay()]);

        $this->createShirt()->assertRedirect($this->url('/app/assinatura'));
        $this->assertSame(0, $this->inStore(fn () => Product::count()));
        $this->get($this->url('/app/produtos'))->assertOk();
    }
}
