<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Catalog\CatalogService;
use App\Commerce\Catalog\Product;
use App\Commerce\Catalog\Selection;
use App\Commerce\Catalog\Variant;
use App\Commerce\Inventory\StockService;
use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seleções do catálogo: a lojista marca algumas peças e manda às clientes um link só com elas, com o estoque
 * de agora e o pedido pelo WhatsApp. Editável, desativável, e nada sai do estoque.
 */
class SelectionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    /** @var array<string, string> nome => id do produto */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->store = app(OpenStore::class)->open($this->owner, 'Fran Lingerie');
        $this->actingAs($this->owner);
        $this->inStore(function () {
            $catalog = app(CatalogService::class);
            $catalog->create(['name' => 'Sutiã Renda', 'price_cents' => 8990, 'sku' => 'SUT'], ['Tamanho'], [['42', '44']]);
            $catalog->create(['name' => 'Calcinha Fio', 'price_cents' => 2990, 'sku' => 'CAL'], ['Tamanho'], [['P', 'M']]);
            $catalog->create(['name' => 'Body Cetim', 'price_cents' => 11990, 'sku' => 'BOD'], [], []); // sem estoque
            $catalog->create(['name' => 'Pijama Inverno', 'price_cents' => 10990, 'sku' => 'PIJ'], [], []);
            $stock = app(StockService::class);
            $stock->receive(Variant::where('sku', 'SUT-42')->sole(), 2);
            $stock->receive(Variant::where('sku', 'CAL-M')->sole(), 5);
            $stock->receive(Variant::where('sku', 'PIJ')->sole(), 1);
            $this->p = Product::pluck('id', 'name')->all();
        });
    }

    private function url(string $path): string
    {
        return 'http://'.config('foxy.hosts.commerce').$path;
    }

    private function inStore(callable $callback): mixed
    {
        return app(ContextStore::class)->run(ExecutionContext::forUser($this->store->organization, $this->owner->id), $callback);
    }

    private function publishCatalog(): void
    {
        $this->post($this->url('/app/loja'), ['name' => 'Fran Lingerie', 'whatsapp' => '(15) 99999-0000', 'mode' => 'catalogo'])->assertSessionHasNoErrors();
        $this->post($this->url('/app/loja/publicar'), ['publish' => 1])->assertSessionHasNoErrors();
    }

    public function test_marca_pecas_em_produtos_cria_a_selecao_e_a_cliente_ve_so_elas_com_o_estoque(): void
    {
        $this->get($this->url('/app/produtos'))->assertOk()->assertSee('Mandar seleção')->assertSee('form="selection-form"', false);

        // Da lista de produtos vem para a tela da seleção já com as peças marcadas.
        $form = $this->get($this->url('/app/selecoes/nova?'.http_build_query(['produtos' => [$this->p['Calcinha Fio'], $this->p['Sutiã Renda']]])))->assertOk()
            ->assertSee('A loja ainda não está publicada');
        $this->assertMatchesRegularExpression('/value="'.$this->p['Calcinha Fio'].'"[^>]*checked/', $form->getContent());
        $this->assertDoesNotMatchRegularExpression('/value="'.$this->p['Pijama Inverno'].'"[^>]*checked/', $form->getContent());

        $this->post($this->url('/app/selecoes'), ['title' => 'Novidades da semana', 'products' => []])->assertSessionHasErrors(['products' => 'Marque pelo menos uma peça.']);
        $this->post($this->url('/app/selecoes'), ['title' => 'Novidades da semana', 'only_in_stock' => '1',
            'products' => [$this->p['Calcinha Fio'], $this->p['Sutiã Renda'], $this->p['Body Cetim'], '01inexistente000000000000x']])->assertSessionHasNoErrors();
        $selection = $this->inStore(fn () => Selection::sole());
        $this->assertSame([$this->p['Calcinha Fio'], $this->p['Sutiã Renda'], $this->p['Body Cetim']], $selection->product_ids); // a que não é da loja fica fora
        $link = "/fran-lingerie/s/{$selection->token}";
        $this->get($this->url("/app/selecoes/{$selection->id}"))->assertOk()->assertSee($link)->assertSee('Mandar no WhatsApp')
            ->assertSee('wa.me/?text='.rawurlencode("Novidades da semana:\n"), false);
        $this->get($this->url('/app/selecoes'))->assertOk()->assertSee('Novidades da semana');
        $this->assertStringContainsString($link, $this->get($this->url('/app/selecoes?exportar=planilha'))->streamedContent());

        // Loja fora do ar: o link não abre.
        $this->get($this->url($link))->assertNotFound();
        $this->publishCatalog();
        auth()->logout();

        $page = $this->get($this->url($link))->assertOk()->assertSee('Novidades da semana')->assertSee('Calcinha Fio')->assertSee('Sutiã Renda')
            ->assertSee('Últimas 2 unidades')->assertDontSee('Body Cetim')->assertDontSee('Pijama Inverno'); // esgotado some; o que não foi escolhido não aparece
        $this->assertLessThan(strpos($page->getContent(), 'Sutiã Renda'), strpos($page->getContent(), 'Calcinha Fio')); // na ordem escolhida
        $this->assertStringContainsString('wa.me/5515999990000?text=', $page->getContent());
        $this->get($this->url('/fran-lingerie/s/'.str_repeat('x', 32)))->assertNotFound();

        // Mostrando também o esgotado.
        $this->actingAs($this->owner)->post($this->url("/app/selecoes/{$selection->id}"), ['title' => 'Novidades', 'products' => [$this->p['Body Cetim'], $this->p['Pijama Inverno']]])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get($this->url($link))->assertOk()->assertSee('Body Cetim')->assertSee('Esgotado')->assertSee('Pijama Inverno')->assertDontSee('Calcinha Fio');

        // Desativada, o link para de abrir; reativada, volta.
        $this->actingAs($this->owner)->post($this->url("/app/selecoes/{$selection->id}/situacao"))->assertSessionHas('status', 'Seleção desativada: o link não abre mais.');
        auth()->logout();
        $this->get($this->url($link))->assertNotFound();
        $this->actingAs($this->owner)->post($this->url("/app/selecoes/{$selection->id}/situacao"));
        auth()->logout();
        $this->get($this->url($link))->assertOk();
        $this->assertSame(8, $this->inStore(fn () => (int) \App\Commerce\Inventory\StockLevel::sum('on_hand'))); // nada sai do estoque
    }

    public function test_atendente_monta_selecao_e_outra_loja_nao_ve(): void
    {
        $attendant = User::factory()->create();
        Membership::create(['organization_id' => $this->store->organization_id, 'user_id' => $attendant->id, 'role' => 'attendant']);
        $this->actingAs($attendant)->get($this->url('/app/selecoes'))->assertOk();
        $this->post($this->url('/app/selecoes'), ['title' => 'Para a Bruna', 'products' => [$this->p['Calcinha Fio']]])->assertSessionHasNoErrors();
        $selection = $this->inStore(fn () => Selection::sole());

        $other = User::factory()->create();
        app(OpenStore::class)->open($other, 'Outra Loja');
        $this->actingAs($other)->get($this->url("/app/selecoes/{$selection->id}"))->assertNotFound();
        $this->post($this->url("/app/selecoes/{$selection->id}/situacao"))->assertNotFound();
        $this->post($this->url('/app/selecoes'), ['title' => 'Roubada', 'products' => [$this->p['Calcinha Fio']]])->assertSessionHasErrors('products');
    }
}
