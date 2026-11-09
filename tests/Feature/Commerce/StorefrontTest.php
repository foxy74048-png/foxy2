<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Catalog\CatalogService;
use App\Commerce\Catalog\Variant;
use App\Commerce\Inventory\StockLevel;
use App\Commerce\Inventory\StockService;
use App\Commerce\Orders\Order;
use App\Commerce\Storefront\PixCode;
use App\Commerce\Storefront\StorefrontOrderNotification;
use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Models\User;
use App\Platform\Billing\Subscription;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Foxy Commerce, fatia 6 (docs/09, seção 7): loja própria pública, carrinho, pedido com reserva e Pix do lojista.
 */
class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    /** @var array<string, Variant> */
    private array $v = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['email' => 'ana@loja.test']);
        $this->store = app(OpenStore::class)->open($this->owner, 'Moda da Ana', '11988887777');
        $this->inStore(function () {
            app(CatalogService::class)->create(['name' => 'Camiseta Básica', 'price_cents' => 7990, 'sku' => 'CAM'], ['Tamanho'], [['P', 'M']]);
            $this->v = Variant::all()->keyBy('sku')->all();
            app(StockService::class)->receive($this->v['CAM-P'], 3);
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

    private function configure(): void
    {
        $this->actingAs($this->owner)->post($this->url('/app/loja'), [
            'name' => 'Moda da Ana', 'whatsapp' => '11988887777', 'primary_color' => '#0f766e',
            'pix_key' => 'ana@loja.test', 'pix_name' => 'Ana Souza', 'pix_city' => 'Campinas',
            'delivery' => ['pickup' => '1', 'pickup_address' => 'Rua A, 1', 'local' => '1', 'local_fee' => '12,00', 'shipping' => '0'],
        ])->assertSessionHasNoErrors();
        $this->post($this->url('/app/loja/publicar'), ['publish' => 1])->assertSessionHasNoErrors();
        auth()->logout();
    }

    public function test_crc_do_pix_no_padrao_do_banco_central(): void
    {
        $this->assertSame('29B1', PixCode::crc16('123456789')); // valor de verificação do CRC-16/CCITT-FALSE
        $payload = PixCode::payload('ana@loja.test', 'Ana Souza', 'Campinas', 8790, 'PEDIDO1');
        $this->assertStringStartsWith('000201', $payload);
        $this->assertStringContainsString('0014br.gov.bcb.pix0113ana@loja.test', $payload);
        $this->assertStringContainsString('540587.90', $payload);
        $this->assertStringContainsString('5909ANA SOUZA6008CAMPINAS', $payload);
        $this->assertSame(PixCode::crc16(substr($payload, 0, -4)), substr($payload, -4));
    }

    public function test_loja_nao_publicada_nao_aparece_e_publicar_exige_pix_e_entrega(): void
    {
        $this->get($this->url('/moda-da-ana'))->assertNotFound();
        $this->actingAs($this->owner)->post($this->url('/app/loja/publicar'), ['publish' => 1])->assertSessionHasErrors('publish');
    }

    public function test_compra_completa_reserva_as_pecas_e_mostra_o_pix(): void
    {
        Notification::fake();
        $this->configure();

        $this->get($this->url('/moda-da-ana'))->assertOk()->assertSee('Camiseta Básica')->assertSee('#0f766e', false);
        $product = $this->v['CAM-P']->product_id;
        $this->get($this->url("/moda-da-ana/p/{$product}"))->assertOk()->assertSee('P — R$ 79,90')->assertSee('M — R$ 79,90 (esgotado)')
            ->assertSee('data-picker', false)->assertSee('<label for="opt-0">Tamanho</label>', false); // uma escolha por característica

        // Mais do que o disponível não entra.
        $this->post($this->url('/moda-da-ana/carrinho'), ['variant_id' => $this->v['CAM-P']->id, 'quantity' => 4])->assertSessionHasErrors('quantity');
        $this->post($this->url('/moda-da-ana/carrinho'), ['variant_id' => $this->v['CAM-P']->id, 'quantity' => 2])->assertRedirect($this->url('/moda-da-ana/carrinho'));
        $this->get($this->url('/moda-da-ana/carrinho'))->assertSee('R$ 159,80');

        $response = $this->post($this->url('/moda-da-ana/finalizar'), ['name' => 'Bruna Lima', 'phone' => '11977776666', 'email' => 'bruna@cliente.test', 'delivery' => 'local', 'address' => 'Rua B, 2', 'city' => 'Campinas']);
        $order = $this->inStore(fn () => Order::with('customer')->sole());
        $response->assertRedirect();
        $this->assertStringContainsString('/moda-da-ana/pedido/'.$order->id.'?', $response->headers->get('Location'));

        $this->assertSame('loja', $order->channel);
        $this->assertSame('awaiting_payment', $order->status);
        $this->assertSame(15980 + 1200, $order->total_cents);
        $this->assertSame('Bruna Lima', $order->customer->name);
        $this->assertStringContainsString('Entrega local', $order->notes);
        $level = $this->inStore(fn () => StockLevel::where('variant_id', $this->v['CAM-P']->id)->first());
        $this->assertSame([3, 2], [$level->on_hand, $level->reserved]);

        $page = $this->get($response->headers->get('Location'))->assertOk()->assertSee('Pix copia e cola')->assertSee('data:image/svg+xml', false)->assertSee('wa.me/5511988887777', false);
        $this->assertStringContainsString('0014br.gov.bcb.pix', $page->getContent());
        $this->assertStringContainsString('5406171.80', $page->getContent());

        // Sem a assinatura do link, ninguém vê o pedido.
        $this->get($this->url('/moda-da-ana/pedido/'.$order->id))->assertForbidden();

        Notification::assertSentTo($this->owner, StorefrontOrderNotification::class, fn ($n) => $n->forOwner);
        Notification::assertSentTo(new AnonymousNotifiable, StorefrontOrderNotification::class, fn ($n, $c, $to) => $to->routes['mail'] === 'bruna@cliente.test' && ! $n->forOwner);

        // O carrinho esvazia e o pedido aparece para o lojista.
        $this->get($this->url('/moda-da-ana/carrinho'))->assertSee('carrinho está vazio');
        $this->actingAs($this->owner)->get($this->url('/app/pedidos'))->assertSee('Loja própria')->assertSee('Bruna Lima');
    }

    public function test_variante_de_outra_loja_nao_entra_no_carrinho(): void
    {
        $this->configure();
        $bia = User::factory()->create();
        $lojaB = app(OpenStore::class)->open($bia, 'Loja da Bia');
        $alheia = app(ContextStore::class)->run(ExecutionContext::forUser($lojaB->organization, $bia->id), function () {
            app(CatalogService::class)->create(['name' => 'Bolsa', 'price_cents' => 100, 'sku' => 'BOLSA'], [], []);

            return Variant::sole();
        });

        $this->post($this->url('/moda-da-ana/carrinho'), ['variant_id' => $alheia->id])->assertNotFound();
        $this->get($this->url("/moda-da-ana/p/{$alheia->product_id}"))->assertNotFound();
    }

    public function test_loja_com_assinatura_vencida_fica_indisponivel(): void
    {
        $this->configure();
        Subscription::where('organization_id', $this->store->organization_id)->update(['trial_ends_at' => now()->subDay()]);

        $this->get($this->url('/moda-da-ana'))->assertStatus(503)->assertSee('temporariamente indisponível');
    }

    public function test_enderecos_do_app_nao_viram_loja(): void
    {
        $this->configure();
        $this->get($this->url('/entrar'))->assertOk()->assertSee('Entrar');
        $this->get($this->url('/loja-que-nao-existe'))->assertNotFound();
    }
}
