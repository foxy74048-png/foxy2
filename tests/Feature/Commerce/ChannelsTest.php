<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Catalog\CatalogService;
use App\Commerce\Catalog\Product;
use App\Commerce\Catalog\Variant;
use App\Commerce\Channels\ChannelConnection;
use App\Commerce\Channels\Channels;
use App\Commerce\Channels\ExternalOrder;
use App\Commerce\Channels\FakeConnector;
use App\Commerce\Channels\InboxItem;
use App\Commerce\Channels\Listing;
use App\Commerce\Inventory\StockLevel;
use App\Commerce\Inventory\StockService;
use App\Commerce\Orders\Order;
use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Foxy Commerce, fatia 7 (docs/09, seção 6): conectar marketplace, anunciar, estoque indo sozinho, pedidos
 * voltando pelos avisos e pela conferência. Nenhum marketplace de verdade: FakeConnector.
 */
class ChannelsTest extends TestCase
{
    use RefreshDatabase;

    private FakeConnector $market;

    private User $owner;

    private Store $store;

    /** @var array<string, Variant> */
    private array $v = [];

    protected function setUp(): void
    {
        parent::setUp();
        Channels::fake($this->market = new FakeConnector);
        $this->owner = User::factory()->create();
        $this->store = app(OpenStore::class)->open($this->owner, 'Moda da Ana');

        $this->inStore(function () {
            $catalog = app(CatalogService::class);
            $catalog->create(['name' => 'Camiseta', 'price_cents' => 8000, 'cost_cents' => 3000, 'sku' => 'CAM'], ['Tamanho'], [['P', 'M']]);
            $catalog->create(['name' => 'Boné', 'price_cents' => 5000, 'sku' => 'BONE'], [], []);
            app(StockService::class)->receive(Variant::where('sku', 'CAM-P')->first(), 5);
            app(StockService::class)->receive(Variant::where('sku', 'CAM-M')->first(), 3);
            $this->v = Variant::all()->keyBy('sku')->all();
        });
    }

    protected function tearDown(): void
    {
        Channels::fake(null);
        parent::tearDown();
    }

    private function url(string $path): string
    {
        return 'http://'.config('foxy.hosts.commerce').$path;
    }

    private function inStore(callable $callback): mixed
    {
        return app(ContextStore::class)->run(ExecutionContext::forUser($this->store->organization, $this->owner->id), $callback);
    }

    /** Fluxo de conectar: vai ao canal com o state, volta com o código. */
    private function connect(string $code = 'abc', ?User $as = null)
    {
        $redirect = $this->actingAs($as ?? $this->owner)->post($this->url('/app/canais/mercadolivre/conectar'))->headers->get('Location');
        parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $query);

        return $this->get($this->url('/app/canais/mercadolivre/retorno?code='.$code.'&state='.($query['state'] ?? '')));
    }

    private function connection(): ChannelConnection
    {
        return $this->inStore(fn () => ChannelConnection::sole());
    }

    private function publishShirt(): Listing
    {
        $this->connect();
        $product = $this->inStore(fn () => Product::where('name', 'Camiseta')->sole());
        $this->post($this->url('/app/anuncios'), [
            'connection_id' => $this->connection()->id, 'product_id' => $product->id, 'title' => 'Camiseta Algodão Básica',
            'price' => '89,90', 'variants' => [$this->v['CAM-P']->id, $this->v['CAM-M']->id], 'publish' => '1',
        ])->assertSessionHasNoErrors();

        return $this->inStore(fn () => Listing::with('variants')->sole());
    }

    private function onHand(string $sku): int
    {
        return $this->inStore(fn () => StockLevel::where('variant_id', $this->v[$sku]->id)->value('on_hand'));
    }

    public function test_conectar_conta_do_marketplace_com_token_criptografado(): void
    {
        $this->connect()->assertRedirect($this->url('/app/canais'))->assertSessionHas('status');

        $connection = $this->connection();
        $this->assertSame('vendedor-abc', $connection->external_account_id);
        $this->assertSame('tok-abc', $connection->access_token);
        $this->assertNotSame('tok-abc', DB::table('commerce_channel_connections')->value('access_token'));
        $this->get($this->url('/app/canais'))->assertOk()->assertSee('LOJA_ABC')->assertDontSee('tok-abc');
    }

    public function test_retorno_sem_o_state_da_sessao_ou_com_codigo_invalido_nao_conecta(): void
    {
        $this->actingAs($this->owner)->get($this->url('/app/canais/mercadolivre/retorno?code=abc&state=forjado'))->assertSessionHasErrors('channel');
        $this->connect('invalido')->assertSessionHasErrors('channel');
        $this->assertSame(0, $this->inStore(fn () => ChannelConnection::count()));
    }

    public function test_mesma_conta_nao_entra_em_duas_lojas_e_limite_do_plano(): void
    {
        $this->connect();
        $bia = User::factory()->create();
        app(OpenStore::class)->open($bia, 'Loja da Bia');
        $this->connect('abc', $bia)->assertSessionHasErrors('channel');

        config(['commerce.trial_limits.channels' => 1]);
        $this->actingAs($this->owner)->post($this->url('/app/canais/mercadolivre/conectar'))->assertSessionHasErrors('channel');
    }

    public function test_anuncio_publicado_com_o_estoque_de_cada_variante(): void
    {
        $listing = $this->publishShirt();

        $this->assertSame('active', $listing->status);
        $this->assertSame('ANUNCIO-1', $listing->external_id);
        $this->assertSame(8990, $this->market->published[0]['price']);
        $this->assertSame([5, 3], array_column($this->market->published[0]['variants'], 'quantity'));
        $this->assertSame([$this->v['CAM-P']->id => 5, $this->v['CAM-M']->id => 3], $listing->variants->pluck('pushed_quantity', 'variant_id')->sortDesc()->all());
        $this->get($this->url("/app/anuncios/{$listing->id}"))->assertOk()->assertSee('No ar')->assertSee('ANUNCIO-1');
    }

    public function test_erro_do_canal_aparece_no_anuncio(): void
    {
        $this->market->failPublishWith = 'Categoria obrigatória para este produto.';
        $listing = $this->publishShirt();

        $this->assertSame('error', $listing->status);
        $this->get($this->url("/app/anuncios/{$listing->id}"))->assertSee('Categoria obrigatória')->assertSee('Tentar publicar de novo');
        $this->get($this->url('/app/anuncios'))->assertSee('Com erro')->assertSee('Apagar');

        // Ainda não está no canal: pode apagar. Publicado não se apaga, se pausa.
        $this->post($this->url("/app/anuncios/{$listing->id}/apagar"))->assertSessionHasNoErrors();
        $this->assertSame(0, $this->inStore(fn () => Listing::count()));
        $this->market->failPublishWith = null;
        $active = $this->publishShirt();
        $this->post($this->url("/app/anuncios/{$active->id}/apagar"))->assertSessionHasErrors('status');
        $this->get($this->url('/app/anuncios'))->assertSee('Pausar');
    }

    public function test_estoque_vai_sozinho_so_com_o_que_mudou(): void
    {
        $listing = $this->publishShirt();
        $this->market->stockPushes = [];

        $this->post($this->url('/app/estoque/entrada'), ['items' => [['sku' => 'CAM-M', 'quantity' => 4]]])->assertSessionHasNoErrors();

        $this->assertCount(1, $this->market->stockPushes);
        $this->assertSame([$listing->variants->firstWhere('variant_id', $this->v['CAM-M']->id)->external_variation_id => 7], $this->market->stockPushes[0]['quantities']);

        // Mudança em produto sem anúncio não manda nada.
        $this->inStore(fn () => app(StockService::class)->receive($this->v['BONE'], 2));
        $this->assertCount(1, $this->market->stockPushes);
    }

    public function test_aviso_de_pedido_importa_uma_vez_baixa_estoque_e_atualiza_os_outros_anuncios(): void
    {
        $this->publishShirt();
        $this->market->stockPushes = [];
        $this->market->orders['/orders/9001'] = new ExternalOrder('9001', 'paid', [['sku' => 'cam-p', 'quantity' => 2, 'unit_price_cents' => 8990]], now()->subMinutes(5), feeCents: 2877, buyerName: 'Carla Souza', city: 'Campinas', state: 'SP');

        $notice = ['topic' => 'orders_v2', 'resource' => '/orders/9001', 'user_id' => 'vendedor-abc'];
        $this->postJson($this->url('/webhooks/commerce/mercadolivre'), $notice)->assertOk();
        $this->postJson($this->url('/webhooks/commerce/mercadolivre'), $notice)->assertOk();

        $order = $this->inStore(fn () => Order::with('customer')->sole());
        $this->assertSame('mercadolivre', $order->channel);
        $this->assertSame('9001', $order->external_id);
        $this->assertSame('paid', $order->status);
        $this->assertSame(2877, $order->fee_cents);
        $this->assertSame('Carla Souza', $order->customer->name);
        $this->assertSame(3, $this->onHand('CAM-P'));
        $this->assertSame([3], array_values(end($this->market->stockPushes)['quantities']));
        $this->assertSame(2, InboxItem::whereNotNull('processed_at')->count());

        // Cancelado no canal: cancela aqui e devolve.
        $this->market->orders['/orders/9001'] = new ExternalOrder('9001', 'canceled', [['sku' => 'CAM-P', 'quantity' => 2, 'unit_price_cents' => 8990]], now());
        $this->postJson($this->url('/webhooks/commerce/mercadolivre'), $notice)->assertOk();
        $this->assertSame('canceled', $order->fresh()->status);
        $this->assertSame(5, $this->onHand('CAM-P'));
    }

    public function test_sku_desconhecido_pede_atencao_e_entra_depois_na_conferencia(): void
    {
        $this->connect();
        $this->market->orders['/orders/77'] = new ExternalOrder('77', 'paid', [['sku' => 'NOVO-1', 'quantity' => 1, 'unit_price_cents' => 5000]], now());
        $this->postJson($this->url('/webhooks/commerce/mercadolivre'), ['topic' => 'orders_v2', 'resource' => '/orders/77', 'user_id' => 'vendedor-abc'])->assertOk();

        $this->assertSame('needs_attention', $this->connection()->status);
        $this->assertStringContainsString('NOVO-1', $this->connection()->last_error);
        $this->assertSame(0, $this->inStore(fn () => Order::count()));
        $this->get($this->url('/app/canais'))->assertSee('NOVO-1')->assertSee('Precisa de atenção');

        // Lojista cadastra o SKU; a conferência tenta de novo e o pedido entra.
        $this->inStore(fn () => app(CatalogService::class)->create(['name' => 'Novo', 'price_cents' => 5000, 'sku' => 'NOVO-1'], [], []));
        $this->travel(5)->minutes();
        $this->artisan('commerce:canais')->assertSuccessful();
        $this->assertSame(1, $this->inStore(fn () => Order::count()));
    }

    public function test_conferencia_renova_token_e_busca_pedidos_perdidos(): void
    {
        $this->connect();
        $this->inStore(fn () => ChannelConnection::sole()->update(['token_expires_at' => now()->addMinutes(10)]));
        $this->market->orders['/orders/5'] = new ExternalOrder('5', 'paid', [['sku' => 'CAM-M', 'quantity' => 1, 'unit_price_cents' => 8990]], now()->subHour());
        $this->market->recent = ['/orders/5'];

        $this->artisan('commerce:canais')->assertSuccessful();
        $this->artisan('commerce:canais')->assertSuccessful();

        $this->assertSame(1, $this->market->refreshes); // renovou uma vez; na segunda o token já estava novo
        $this->assertSame('tok-novo', $this->connection()->access_token);
        $this->assertSame(1, $this->inStore(fn () => Order::count()));
        $this->assertNotNull($this->connection()->last_synced_at);
    }

    public function test_desconectar_pausa_os_anuncios_e_apaga_os_tokens(): void
    {
        $listing = $this->publishShirt();
        $this->post($this->url("/app/canais/conexoes/{$this->connection()->id}/desconectar"))->assertSessionHasNoErrors();

        $this->assertSame('disconnected', $this->connection()->status);
        $this->assertNull($this->connection()->access_token);
        $this->assertSame('paused', $listing->fresh()->status);

        // Aviso de conta desconectada não importa nada.
        $this->market->orders['/orders/1'] = new ExternalOrder('1', 'paid', [['sku' => 'CAM-P', 'quantity' => 1, 'unit_price_cents' => 1]], now());
        $this->postJson($this->url('/webhooks/commerce/mercadolivre'), ['topic' => 'orders_v2', 'resource' => '/orders/1', 'user_id' => 'vendedor-abc'])->assertOk();
        $this->assertSame(0, $this->inStore(fn () => Order::count()));
    }

    public function test_titulo_acima_do_limite_do_canal_e_outra_loja_e_atendente(): void
    {
        $this->connect();
        $product = $this->inStore(fn () => Product::where('name', 'Boné')->sole());
        $this->post($this->url('/app/anuncios'), [
            'connection_id' => $this->connection()->id, 'product_id' => $product->id, 'title' => str_repeat('a', 61), 'price' => '50', 'variants' => [$this->v['BONE']->id],
        ])->assertSessionHasErrors('title');

        $listing = $this->publishShirt();
        $bia = User::factory()->create();
        app(OpenStore::class)->open($bia, 'Loja da Bia');
        $this->actingAs($bia)->get($this->url("/app/anuncios/{$listing->id}"))->assertNotFound();

        $attendant = User::factory()->create();
        Membership::create(['organization_id' => $this->store->organization_id, 'user_id' => $attendant->id, 'role' => 'attendant']);
        $this->actingAs($attendant)->get($this->url('/app/anuncios'))->assertForbidden();
        $this->actingAs($attendant)->get($this->url('/app/canais'))->assertForbidden();
    }
}
