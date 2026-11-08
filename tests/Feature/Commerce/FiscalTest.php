<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Catalog\CatalogService;
use App\Commerce\Catalog\Variant;
use App\Commerce\Channels\ChannelConnection;
use App\Commerce\Channels\Channels;
use App\Commerce\Channels\FakeConnector;
use App\Commerce\Fiscal\FiscalDocument;
use App\Commerce\Fiscal\NfeXml;
use App\Commerce\Orders\Order;
use App\Commerce\Orders\OrderService;
use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Foxy Commerce, fatia 9 (fiscal, fase A): nota emitida fora do Foxy ligada ao pedido (XML ou chave) e
 * notas trazidas pelo canal na conferência.
 */
class FiscalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->store = app(OpenStore::class)->open($this->owner, 'Moda da Ana');
        $this->order = $this->inStore(function () {
            app(CatalogService::class)->create(['name' => 'Boné', 'price_cents' => 5000, 'sku' => 'BONE'], [], []);

            return app(OrderService::class)->place(['channel' => 'balcao', 'paid' => true, 'items' => [['variant' => Variant::sole(), 'quantity' => 1]]]);
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

    private function key(string $number = '000001234'): string
    {
        $first = '35'.'2611'.'12345678000190'.'55'.'001'.$number.'1'.'12345678';

        return $first.NfeXml::checkDigit($first);
    }

    private function xml(string $key, string $total = '50.00'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><NFe><infNFe Id="NFe'.$key.'" versao="4.00">'
            .'<ide><serie>1</serie><nNF>1234</nNF><dhEmi>2026-11-08T10:00:00-03:00</dhEmi></ide><total><ICMSTot><vNF>'.$total.'</vNF></ICMSTot></total></infNFe></NFe></nfeProc>';
    }

    public function test_chave_de_acesso_confere_o_digito(): void
    {
        $this->assertTrue(NfeXml::validKey($this->key()));
        $this->assertFalse(NfeXml::validKey(substr($this->key(), 0, 43).'9' === $this->key() ? substr($this->key(), 0, 43).'8' : substr($this->key(), 0, 43).'9'));
        $this->assertFalse(NfeXml::validKey('123'));
    }

    public function test_anexar_xml_guarda_em_disco_privado_e_baixa_pela_rota(): void
    {
        Storage::fake('local');
        $key = $this->key();
        $this->actingAs($this->owner)->post($this->url("/app/pedidos/{$this->order->id}/nota"), [
            'xml' => UploadedFile::fake()->createWithContent('nota.xml', $this->xml($key)),
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        $doc = $this->inStore(fn () => FiscalDocument::with('xml')->sole());
        $this->assertSame($key, $doc->access_key);
        $this->assertSame('1234', $doc->number);
        $this->assertSame(5000, $doc->total_cents);
        $this->assertSame('local', $doc->xml->disk);
        Storage::disk('local')->assertExists($doc->xml->path);

        $this->get($this->url("/app/pedidos/{$this->order->id}"))->assertSee('NF-e 1234')->assertSee('Baixar XML');
        $this->get($this->url("/app/pedidos/{$this->order->id}/nota/{$doc->id}/xml"))->assertOk()->assertDownload('NFe'.$key.'.xml');

        // A mesma nota não entra em outro pedido.
        $other = $this->inStore(fn () => app(OrderService::class)->place(['channel' => 'balcao', 'paid' => true, 'items' => [['variant' => Variant::sole(), 'quantity' => 1]]]));
        $this->post($this->url("/app/pedidos/{$other->id}/nota"), ['access_key' => $key])->assertSessionHasErrors('access_key');
    }

    public function test_chave_digitada_e_xml_invalido(): void
    {
        $this->actingAs($this->owner)->post($this->url("/app/pedidos/{$this->order->id}/nota"), ['access_key' => '1234'])->assertSessionHasErrors('access_key');
        $this->post($this->url("/app/pedidos/{$this->order->id}/nota"), ['xml' => UploadedFile::fake()->createWithContent('x.xml', '<html>nao</html>')])->assertSessionHasErrors('xml');

        $spaced = trim(chunk_split($this->key(), 4, ' '));
        $this->post($this->url("/app/pedidos/{$this->order->id}/nota"), ['access_key' => $spaced])->assertSessionHasNoErrors();
        $doc = $this->inStore(fn () => FiscalDocument::sole());
        $this->assertSame('1234', $doc->number);
        $this->assertSame('1', $doc->series);
    }

    public function test_xml_com_entidade_externa_nao_le_arquivos_do_servidor(): void
    {
        $evil = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><nfeProc><NFe><infNFe Id="NFe&e;"><ide><nNF>1</nNF></ide></infNFe></NFe></nfeProc>';
        $this->actingAs($this->owner)->post($this->url("/app/pedidos/{$this->order->id}/nota"), ['xml' => UploadedFile::fake()->createWithContent('x.xml', $evil)])
            ->assertSessionHasErrors('xml');
        $this->assertSame(0, $this->inStore(fn () => FiscalDocument::count()));
    }

    public function test_filtro_de_vendas_sem_nota_e_planilha_com_a_chave(): void
    {
        $this->actingAs($this->owner)->get($this->url('/app/pedidos?nota=sem'))->assertOk()->assertSee('<strong>#1</strong>', false);
        $this->get($this->url('/app'))->assertSee('Vendas sem nota (30 dias)');

        $this->post($this->url("/app/pedidos/{$this->order->id}/nota"), ['access_key' => $this->key()]);
        $this->get($this->url('/app/pedidos?nota=sem'))->assertDontSee('<strong>#1</strong>', false);
        $this->get($this->url('/app/pedidos?nota=com'))->assertSee('<strong>#1</strong>', false);
        $this->assertStringContainsString($this->key(), $this->get($this->url('/app/pedidos/exportar'))->streamedContent());
    }

    public function test_outra_loja_nao_ve_nem_baixa_a_nota(): void
    {
        Storage::fake('local');
        $this->actingAs($this->owner)->post($this->url("/app/pedidos/{$this->order->id}/nota"), ['xml' => UploadedFile::fake()->createWithContent('n.xml', $this->xml($this->key()))]);
        $doc = $this->inStore(fn () => FiscalDocument::sole());

        $bia = User::factory()->create();
        app(OpenStore::class)->open($bia, 'Loja da Bia');
        $this->actingAs($bia)->get($this->url("/app/pedidos/{$this->order->id}/nota/{$doc->id}/xml"))->assertNotFound();
    }

    public function test_conferencia_traz_a_nota_do_canal(): void
    {
        Channels::fake($market = new FakeConnector);
        $connection = $this->inStore(fn () => ChannelConnection::create(['channel' => 'mercadolivre', 'external_account_id' => '123', 'access_token' => 'x']));
        $mlOrder = $this->inStore(fn () => app(OrderService::class)->place(['channel' => 'mercadolivre', 'external_id' => '2000001', 'paid' => true, 'items' => [['variant' => Variant::sole(), 'quantity' => 1]]]));
        $market->invoices['2000001'] = ['access_key' => $this->key('000005555'), 'number' => '5555', 'series' => '1', 'issued_at' => now(), 'total_cents' => 5000];

        $this->artisan('commerce:canais')->assertSuccessful();

        $doc = $this->inStore(fn () => FiscalDocument::sole());
        $this->assertSame($mlOrder->id, $doc->order_id);
        $this->assertSame('mercadolivre', $doc->source);
        $this->assertSame('active', $connection->fresh()->status);
    }
}
