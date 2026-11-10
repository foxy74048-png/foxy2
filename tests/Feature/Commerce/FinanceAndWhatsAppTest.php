<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Assistant\CommerceHost;
use App\Commerce\Catalog\CatalogService;
use App\Commerce\Catalog\Category;
use App\Commerce\Catalog\Variant;
use App\Commerce\Finance\FinanceService;
use App\Commerce\Finance\Payable;
use App\Commerce\Finance\Receivable;
use App\Commerce\Finance\WhatsAppMessage;
use App\Commerce\Inventory\StockLevel;
use App\Commerce\Inventory\StockService;
use App\Commerce\Orders\Customer;
use App\Commerce\Orders\Order;
use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Commerce\Supply\SupplyBatch;
use App\Commerce\WhatsApp\StoreWhatsApp;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Financeiro (a receber com crediário/fiado, a pagar, fluxo) e WhatsApp da loja (cobrança e lembretes).
 * A Foxy não recebe dinheiro: só registra, lembra e mostra o Pix da loja.
 */
class FinanceAndWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private const EVO = 'https://evo.test';

    private User $owner;

    private Store $store;

    private array $v;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        config(['foxy.messaging.whatsapp' => ['api_url' => self::EVO, 'api_key' => 'chave', 'instance' => 'foxy', 'recreate_pause_us' => 0], 'commerce.whatsapp_pause_seconds' => [0, 0]]);
        $this->owner = User::factory()->create();
        $this->store = app(OpenStore::class)->open($this->owner, 'Fran Lingerie');
        $this->store->update(['pix_key' => 'fran@exemplo.com', 'pix_name' => 'Fran Lingerie', 'pix_city' => 'Sao Paulo']);

        $this->inStore(function () {
            app(CatalogService::class)->create(['name' => 'Sutiã', 'price_cents' => 9000, 'cost_cents' => 3000, 'sku' => 'SUT'], ['Tamanho'], [['42', '44']]);
            foreach (Variant::all() as $variant) {
                app(StockService::class)->receive($variant, 5);
            }
            $this->v = Variant::all()->keyBy('sku')->all();
        });
        $this->actingAs($this->owner);
    }

    private function url(string $path): string
    {
        return 'http://'.config('foxy.hosts.commerce').$path;
    }

    private function inStore(callable $callback): mixed
    {
        return app(ContextStore::class)->run(ExecutionContext::forUser($this->store->organization, $this->owner->id), $callback);
    }

    private function fakeEvolution(string $state = 'open'): void
    {
        Http::fake([
            self::EVO.'/instance/connectionState/*' => Http::response(['instance' => ['state' => $state]]),
            self::EVO.'/instance/create' => Http::response(['qrcode' => ['base64' => 'data:image/png;base64,QRQR']]),
            self::EVO.'/message/sendText/*' => Http::response(['key' => ['id' => 'x']]),
            self::EVO.'/*' => Http::response([], 200),
        ]);
    }

    public function test_venda_no_crediario_baixa_o_estoque_e_vira_parcelas(): void
    {
        $this->post($this->url('/app/pedidos'), ['channel' => 'whatsapp', 'payment_method' => 'crediario', 'items' => [['sku' => 'SUT-42', 'quantity' => 1]]])
            ->assertSessionHasErrors('customer.name'); // crediário pede a cliente

        $this->post($this->url('/app/pedidos'), [
            'channel' => 'whatsapp', 'payment_method' => 'crediario', 'installments' => 3, 'first_due' => '2026-12-10',
            'customer' => ['name' => 'Ana Souza', 'phone' => '11988887777'], 'items' => [['sku' => 'SUT-42', 'quantity' => 1], ['sku' => 'SUT-44', 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        [$order, $parcels] = $this->inStore(fn () => [Order::sole(), Receivable::orderBy('due_date')->get()]);
        $this->assertSame('paid', $order->status);
        $this->assertSame(4, $this->inStore(fn () => StockLevel::where('variant_id', $this->v['SUT-42']->id)->value('on_hand')));
        $this->assertSame([6000, 6000, 6000], $parcels->pluck('amount_cents')->all());
        $this->assertSame(['2026-12-10', '2027-01-10', '2027-02-10'], $parcels->map(fn ($r) => $r->due_date->toDateString())->all());
        $this->assertSame('Parcela 2/3', $parcels[1]->installmentLabel());
        $this->get($this->url("/app/pedidos/{$order->id}"))->assertSee('Parcelas a receber')->assertSee('10/01/2027');

        // Pedido cancelado: parcelas em aberto também.
        $this->post($this->url("/app/pedidos/{$order->id}/cancelar"))->assertSessionHasNoErrors();
        $this->assertSame(['cancelado'], $this->inStore(fn () => Receivable::pluck('status')->unique()->values()->all()));
    }

    public function test_a_receber_avulsa_recebimento_parcial_reabrir_e_editar(): void
    {
        $this->post($this->url('/app/financeiro/receber'), ['customer_name' => 'Bia', 'customer_phone' => '11977776666', 'description' => 'Fiado de outubro', 'amount' => '100,01', 'installments' => 2, 'due_date' => '2026-11-05'])
            ->assertSessionHasNoErrors();
        $parcels = $this->inStore(fn () => Receivable::orderBy('due_date')->get());
        $this->assertSame([5001, 5000], $parcels->pluck('amount_cents')->all());
        $this->assertTrue($parcels[0]->isOverdue());

        $this->get($this->url('/app/financeiro?situacao=vencidas'))->assertOk()->assertSee('Bia')->assertSee('5 dia(s) de atraso')->assertDontSee('10/12/2026');

        // Pagou só 30: fica 20,01 em aberto com o mesmo vencimento.
        $this->post($this->url("/app/financeiro/receber/{$parcels[0]->id}/baixa"), ['amount' => '30,00', 'method' => 'pix'])->assertSessionHasNoErrors();
        $this->assertSame(['recebido', 3000], [$parcels[0]->fresh()->status, $parcels[0]->fresh()->received_cents]);
        $rest = $this->inStore(fn () => Receivable::where('status', 'aberto')->where('due_date', '2026-11-05')->sole());
        $this->assertSame(2001, $rest->amount_cents);

        $this->post($this->url("/app/financeiro/receber/{$parcels[0]->id}/reabrir"))->assertSessionHasNoErrors();
        $this->assertSame('aberto', $parcels[0]->fresh()->status);
        $this->post($this->url("/app/financeiro/receber/{$rest->id}"), ['description' => 'Saldo', 'amount' => '25,00', 'due_date' => '2026-11-20'])->assertSessionHasNoErrors();
        $this->assertSame([2500, '2026-11-20'], [$rest->fresh()->amount_cents, $rest->fresh()->due_date->toDateString()]);
        $this->post($this->url("/app/financeiro/receber/{$rest->id}/cancelar"))->assertSessionHasNoErrors();
        $this->post($this->url("/app/financeiro/receber/{$rest->id}/baixa"))->assertSessionHasErrors('status');

        $this->get($this->url("/app/financeiro/receber/{$parcels[1]->id}"))->assertOk()->assertSee('Registrar recebimento')->assertSee('Cobrar pelo WhatsApp');
        $csv = $this->get($this->url('/app/financeiro?situacao=todas&exportar=planilha'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Fiado de outubro', $csv);
        $this->assertStringStartsWith('%PDF', $this->get($this->url('/app/financeiro?exportar=pdf'))->getContent());
    }

    public function test_a_pagar_mensal_parcelado_pagar_cancelar_e_fluxo(): void
    {
        $this->post($this->url('/app/financeiro/pagar'), ['description' => 'Aluguel', 'category' => 'aluguel', 'amount' => '1.200,00', 'due_date' => '2026-11-15', 'mode' => 'mensal', 'count' => 3])->assertSessionHasNoErrors();
        $this->post($this->url('/app/financeiro/pagar'), ['description' => 'Renda do fornecedor', 'supplier' => 'Têxtil X', 'category' => 'fornecedor', 'amount' => '1.000,00', 'due_date' => '2026-11-12', 'mode' => 'parcelas', 'count' => 3])->assertSessionHasNoErrors();

        $rent = $this->inStore(fn () => Payable::where('description', 'Aluguel')->orderBy('due_date')->get());
        $this->assertSame([120000, 120000, 120000], $rent->pluck('amount_cents')->all());
        $this->assertSame([33334, 33333, 33333], $this->inStore(fn () => Payable::where('category', 'fornecedor')->orderBy('due_date')->pluck('amount_cents')->all()));

        $this->post($this->url("/app/financeiro/pagar/{$rent[0]->id}/baixa"))->assertSessionHasNoErrors();
        $this->assertSame('pago', $rent[0]->fresh()->status);
        $this->post($this->url("/app/financeiro/pagar/{$rent[1]->id}/cancelar"), ['future' => 1])->assertSessionHasNoErrors();
        $this->assertSame(['pago', 'cancelado', 'cancelado'], $rent->map(fn ($p) => $p->fresh()->status)->all());

        // Fluxo: a receber 50 hoje (vencida) e o fornecedor a pagar.
        $this->inStore(fn () => app(FinanceService::class)->installments(['description' => 'Fiado'], 5000, 1, Carbon::parse('2026-11-12')));
        $this->get($this->url('/app/financeiro?aba=fluxo'))->assertOk()->assertSee('Esta semana')->assertSee('R$ 50,00')->assertSee('R$ 333,34');
        $this->get($this->url('/app/financeiro?aba=pagar&categoria=fornecedor'))->assertOk()->assertSee('Têxtil X')->assertDontSee('Aluguel</strong>', false);
        $this->assertStringContainsString('Renda do fornecedor', $this->get($this->url('/app/financeiro?aba=pagar&exportar=planilha'))->streamedContent());
    }

    public function test_pedido_com_cliente_da_lista_e_produto_cadastrado_na_hora(): void
    {
        $bia = $this->inStore(fn () => Customer::create(['name' => 'Bia Lima', 'phone' => '11944443333']));
        $this->inStore(fn () => app(FinanceService::class)->installments(['customer_id' => $bia->id, 'description' => 'Fiado'], 4500, 1, Carbon::parse('2026-11-01')));

        $page = $this->get($this->url("/app/pedidos/novo?cliente={$bia->id}"))->assertOk();
        $page->assertSee('"name":"Bia Lima"', false)->assertSee('"open":4500', false)->assertSee('"label":"42"', false)->assertSee('Produto que não está cadastrado');

        // Produto novo de dentro do pedido: categoria nova, tamanhos, cores e estoque inicial.
        $this->postJson($this->url('/app/produtos/rapido'), ['name' => 'Calcinha Lisa', 'new_category' => 'Calcinhas', 'price' => '39,90', 'cost' => '12', 'sizes' => 'P, M', 'colors' => 'Preto', 'stock' => 4])
            ->assertCreated()->assertJsonPath('product.category', 'Calcinhas')->assertJsonCount(2, 'product.variants')
            ->assertJsonPath('product.variants.0.available', 4)->assertJsonPath('product.variants.0.price', 3990);
        $this->postJson($this->url('/app/produtos/rapido'), ['name' => 'Sem preço'])->assertUnprocessable()->assertJsonValidationErrors('price');
        $this->postJson($this->url('/app/produtos/rapido'), ['name' => 'Body', 'new_category' => 'calcinhas', 'price' => '99'])->assertCreated()->assertJsonPath('product.category', 'Calcinhas');
        $this->assertSame(1, $this->inStore(fn () => Category::count())); // não duplica a categoria

        $sku = $this->inStore(fn () => Variant::whereHas('product', fn ($q) => $q->where('name', 'Calcinha Lisa'))->orderBy('position')->value('sku'));
        $this->post($this->url('/app/pedidos'), ['channel' => 'instagram', 'customer_id' => $bia->id, 'customer' => ['name' => 'Bia Lima · 11944443333'], 'paid' => 1, 'payment_method' => 'pix',
            'items' => [['sku' => $sku, 'quantity' => 2]]])->assertSessionHasNoErrors();
        $order = $this->inStore(fn () => Order::latest('number')->first());
        $this->assertSame($bia->id, $order->customer_id);
        $this->assertSame(1, $this->inStore(fn () => Customer::count())); // usou a cliente da lista, não criou outra
        $this->assertSame(7980, $order->total_cents);

        // Atendente registra pedido mas não cadastra produto.
        $attendant = User::factory()->create();
        Membership::create(['organization_id' => $this->store->organization_id, 'user_id' => $attendant->id, 'role' => 'attendant']);
        $this->actingAs($attendant)->postJson($this->url('/app/produtos/rapido'), ['name' => 'X', 'price' => '10'])->assertForbidden();
        $this->get($this->url('/app/pedidos/novo'))->assertOk()->assertDontSee('Produto que não está cadastrado');
    }

    public function test_painel_avisa_vencidas_e_raposa_sabe_quem_deve(): void
    {
        $this->inStore(function () {
            $ana = Customer::create(['name' => 'Ana Souza', 'phone' => '11988887777']);
            app(FinanceService::class)->installments(['customer_id' => $ana->id, 'description' => 'Fiado'], 15000, 1, Carbon::parse('2026-11-01'));
            app(FinanceService::class)->payables(['description' => 'Aluguel', 'category' => 'aluguel', 'amount_cents' => 90000, 'due_date' => '2026-11-12']);
            SupplyBatch::create(['supplier' => 'Miza', 'document_number' => '1945', 'status' => 'aberta', 'closing_on' => '2026-11-14']);
        });

        $this->get($this->url('/app'))->assertOk()->assertSee('Clientes com conta vencida')->assertSee('R$ 150,00')->assertSee('R$ 900,00')->assertSee('Acerto da consignação')->assertSee('Miza');

        $host = app(CommerceHost::class);
        $all = array_keys(CommerceHost::TOOLS);
        $owing = $this->inStore(fn () => $host->run('contas_a_receber', ['so_vencidas' => true], $all));
        $this->assertSame(['R$ 150,00', 'Ana Souza', 9], [$owing['total_vencido'], $owing['contas'][0]['cliente'], $owing['contas'][0]['dias_de_atraso']]);
        $this->assertSame('R$ 900,00', $this->inStore(fn () => $host->run('contas_a_pagar', [], $all))['total']);
        $this->assertSame('Miza nº 1945', $this->inStore(fn () => $host->run('remessas_em_aberto', [], $all))[0]['remessa']);
        $this->assertSame('R$ 150,00', $this->inStore(fn () => $host->run('cliente_buscar', ['busca' => '8888'], $all))['deve_em_aberto']);

        // Atendente: não recebe as consultas de dinheiro nem vê os avisos.
        $attendant = User::factory()->create();
        $membership = Membership::create(['organization_id' => $this->store->organization_id, 'user_id' => $attendant->id, 'role' => 'attendant']);
        $this->assertNotContains('contas_a_receber', CommerceHost::toolsFor($membership));
        $this->assertContains('cliente_buscar', CommerceHost::toolsFor($membership));
        $this->actingAs($attendant)->get($this->url('/app'))->assertOk()->assertDontSee('Clientes com conta vencida');
    }

    public function test_loja_propria_tem_aviso_de_privacidade(): void
    {
        $this->store->update(['published_at' => now(), 'whatsapp' => '11999990000', 'delivery' => ['pickup' => true]]);
        $this->get($this->url('/fran-lingerie'))->assertOk()->assertSee('Privacidade');
        $this->get($this->url('/fran-lingerie/privacidade'))->assertOk()->assertSee('Como usamos os seus dados')->assertSee('LGPD')->assertSee('11999990000')->assertSee('Não vendemos');
    }

    public function test_atendente_nao_ve_o_financeiro(): void
    {
        $attendant = User::factory()->create();
        Membership::create(['organization_id' => $this->store->organization_id, 'user_id' => $attendant->id, 'role' => 'attendant']);
        $this->actingAs($attendant)->get($this->url('/app/financeiro'))->assertForbidden();
        $this->actingAs($attendant)->get($this->url('/app/whatsapp'))->assertForbidden();
        $this->actingAs($attendant)->get($this->url('/app/clientes'))->assertOk()->assertDontSee('Financeiro');
    }

    public function test_cobranca_pelo_whatsapp_da_loja_com_pix_e_sem_conexao_abre_o_whatsapp(): void
    {
        $this->fakeEvolution('open');
        $receivable = $this->inStore(fn () => app(FinanceService::class)->installments(
            ['customer_id' => Customer::create(['name' => 'Carla Dias', 'phone' => '(11) 96666-5555'])->id, 'description' => 'Pedido #7'], 12990, 1, Carbon::parse('2026-11-08'))[0]);

        $this->post($this->url("/app/financeiro/receber/{$receivable->id}/cobrar"), ['kind' => 'lembrete_atraso'])->assertSessionHas('status', 'Cobrança enviada pelo WhatsApp da loja.');
        Http::assertSent(fn (Request $r) => $r->url() === self::EVO.'/message/sendText/foxy_commerce_'.$this->store->organization_id
            && $r['number'] === '5511966665555' && str_contains($r['text'], 'Oi, Carla') && str_contains($r['text'], 'R$ 129,90')
            && str_contains($r['text'], 'venceu em 08/11/2026') && str_contains($r['text'], 'Pix copia e cola') && str_contains($r['text'], '5406129.90'));
        $this->assertSame('enviado', $this->inStore(fn () => WhatsAppMessage::sole()->status));

        // Sem conexão: abre o WhatsApp de quem está usando com a mensagem pronta.
        config(['foxy.messaging.whatsapp.api_url' => null]); // sem o WhatsApp da loja
        $this->post($this->url("/app/financeiro/receber/{$receivable->id}/cobrar"))->assertRedirectContains('https://wa.me/5511966665555?text=');
        $this->assertSame(['enviado', 'manual'], $this->inStore(fn () => WhatsAppMessage::orderBy('created_at')->orderBy('id')->pluck('status')->sort()->values()->all()));
        $this->get($this->url('/app/whatsapp'))->assertOk()->assertSee('Indisponível no momento')->assertSee('Carla Dias')->assertSee('Aberto no WhatsApp');
    }

    public function test_conectar_gera_qr_e_preferencias_dos_lembretes(): void
    {
        $this->fakeEvolution('close');
        $this->postJson($this->url('/app/whatsapp/conectar'))->assertOk()->assertJson(['qr' => 'data:image/png;base64,QRQR']);

        $this->post($this->url('/app/whatsapp/preferencias'), ['reminders' => 1, 'days_before' => 3, 'on_due' => 0, 'after_days' => '7, 1, 1, abc',
            'templates' => ['cobranca' => 'Oi {cliente}, deve {valor}', 'lembrete_dia' => StoreWhatsApp::TEMPLATES['lembrete_dia']]])->assertSessionHasNoErrors();
        $settings = StoreWhatsApp::settings($this->store->fresh());
        $this->assertSame([true, 3, false, [1, 7]], [$settings['reminders'], $settings['days_before'], $settings['on_due'], $settings['after_days']]);
        $this->assertSame('Oi {cliente}, deve {valor}', $settings['templates']['cobranca']);
        $this->assertSame(['cobranca'], array_keys($this->store->fresh()->whatsapp_settings['templates'])); // o padrão não é gravado
    }

    public function test_lembretes_automaticos_saem_uma_vez_por_etapa(): void
    {
        $this->fakeEvolution('open');
        $this->store->update(['whatsapp_settings' => ['reminders' => true, 'days_before' => 2, 'on_due' => true, 'after_days' => [1]]]);
        $ids = $this->inStore(function () {
            $customer = Customer::create(['name' => 'Dani', 'phone' => '11955554444']);
            $make = fn ($due) => app(FinanceService::class)->installments(['customer_id' => $customer->id, 'description' => 'Fiado'], 1000, 1, Carbon::parse($due))[0]->id;

            return ['antes' => $make('2026-11-12'), 'dia' => $make('2026-11-10'), 'atraso' => $make('2026-11-09'), 'longe' => $make('2026-11-20'), 'sem_fone' => app(FinanceService::class)->installments(['description' => 'X'], 1000, 1, Carbon::parse('2026-11-10'))[0]->id];
        });

        $this->artisan('commerce:lembretes')->assertSuccessful();
        $sent = $this->inStore(fn () => WhatsAppMessage::pluck('kind', 'receivable_id')->all());
        $this->assertSame('lembrete_antes', $sent[$ids['antes']]);
        $this->assertSame('lembrete_dia', $sent[$ids['dia']]);
        $this->assertSame('lembrete_atraso', $sent[$ids['atraso']]);
        $this->assertArrayNotHasKey($ids['longe'], $sent);
        $this->assertArrayNotHasKey($ids['sem_fone'], $sent);
        $this->assertArrayHasKey('atraso_1', $this->inStore(fn () => Receivable::find($ids['atraso'])->reminders));

        // Rodou de novo na hora seguinte: nada repete. Fora do horário: nada sai.
        $this->artisan('commerce:lembretes')->assertSuccessful();
        $this->assertSame(3, $this->inStore(fn () => WhatsAppMessage::count()));
        $this->travelTo(Carbon::parse('2026-11-12 22:00', 'America/Sao_Paulo'));
        $this->artisan('commerce:lembretes')->assertSuccessful();
        $this->assertSame(3, $this->inStore(fn () => WhatsAppMessage::count()));
    }
}
