<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Catalog\CatalogService;
use App\Commerce\Catalog\Variant;
use App\Commerce\Consignment\Consignment;
use App\Commerce\Finance\Receivable;
use App\Commerce\Inventory\StockLevel;
use App\Commerce\Inventory\StockService;
use App\Commerce\Orders\Customer;
use App\Commerce\Orders\Order;
use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Commerce\Supply\SupplyBatch;
use App\Commerce\Supply\SupplyItem;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Consignação: maletas com clientes e revendedoras (reserva, reposição, acerto com comissão e fiado) e o
 * painel das consignações recebidas das fornecedoras.
 */
class ConsignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    /** @var array<string, Variant> */
    private array $v = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->store = app(OpenStore::class)->open($this->owner, 'Lingerie da Fran');
        $this->actingAs($this->owner);
        $this->inStore(function () {
            app(CatalogService::class)->create(['name' => 'Calcinha Renda', 'price_cents' => 2990, 'cost_cents' => 1500, 'sku' => 'CR'], ['Tamanho'], [['P', 'M']]);
            $this->v = Variant::all()->keyBy('sku')->all();
            $this->v['CR-M']->update(['gtin' => '7891234567895']);
            app(StockService::class)->receive($this->v['CR-P'], 5);
            app(StockService::class)->receive($this->v['CR-M'], 3);
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

    private function available(string $sku): int
    {
        return $this->inStore(fn () => app(StockService::class)->availability(collect([$this->v[$sku]]))[$this->v[$sku]->id]);
    }

    public function test_maleta_de_revendedora_reserva_repoe_e_acerta_com_comissao_e_fiado(): void
    {
        $this->get($this->url('/app/consignacao'))->assertOk()->assertSee('Nenhuma maleta')->assertSee('+ Nova maleta');
        $this->get($this->url('/app/consignacao/nova'))->assertOk()->assertSee('7891234567895')->assertSee('Calcinha Renda');

        $this->post($this->url('/app/consignacao'), [
            'customer_name' => 'Joana Revendedora', 'customer_phone' => '(15) 99999-0000', 'kind' => 'revendedora', 'commission_percent' => 30,
            'due_on' => today()->addDays(10)->toDateString(), 'items' => [$this->v['CR-P']->id => 4, $this->v['CR-M']->id => 1],
        ])->assertSessionHasNoErrors();
        $maleta = $this->inStore(fn () => Consignment::with('items', 'customer')->sole());
        $this->assertSame([1, 'revendedora', 30.0, 5, 4 * 2990 + 2990], [$maleta->number, $maleta->kind, $maleta->commission_percent, $maleta->pieces(), $maleta->valueCents()]);
        $this->assertSame([1, 2], [$this->available('CR-P'), $this->available('CR-M')]); // reservadas, continuam no estoque
        $this->assertSame(5, $this->inStore(fn () => StockLevel::where('variant_id', $this->v['CR-P']->id)->value('on_hand')));

        // Mais do que o disponível não vai.
        $this->post($this->url("/app/consignacao/{$maleta->id}/pecas"), ['items' => [$this->v['CR-P']->id => 2]])->assertSessionHasErrors('items');
        // Reposição soma na mesma linha.
        $this->post($this->url("/app/consignacao/{$maleta->id}/pecas"), ['items' => [$this->v['CR-M']->id => 1]])->assertSessionHasNoErrors();
        $this->assertSame(2, $this->inStore(fn () => $maleta->items()->where('variant_id', $this->v['CR-M']->id)->value('quantity')));

        $page = $this->get($this->url("/app/consignacao/{$maleta->id}"))->assertOk()->assertSee('Acerto')->assertSee('comissão de 30%');
        $this->assertStringContainsString('wa.me/5515999990000?text=', $page->getContent()); // lista no WhatsApp da revendedora
        $this->assertStringContainsString('Calcinha Renda', $this->get($this->url("/app/consignacao/{$maleta->id}?exportar=planilha"))->streamedContent());
        $this->get($this->url('/app/consignacao'))->assertSee('Joana Revendedora')->assertSee('R$ 179,40'); // 6 peças × 29,90

        // Acerto: das 4 P voltaram 1; das 2 M voltaram 2. Ficaram 3 × 29,90 = 89,70; comissão 30% = 26,91; ela paga 62,79 em 2 parcelas.
        $items = $this->inStore(fn () => $maleta->items()->get()->keyBy('variant_id'));
        $this->post($this->url("/app/consignacao/{$maleta->id}/acerto"), [
            'returns' => [$items[$this->v['CR-P']->id]->id => 1, $items[$this->v['CR-M']->id]->id => 2],
            'payment' => 'receber', 'installments' => 2, 'first_due' => today()->addMonth()->toDateString(),
        ])->assertSessionHasNoErrors();

        $order = $this->inStore(fn () => Order::with('items')->sole());
        $this->assertSame(['consignacao', 'paid', 'crediario', 8970, 2691, 6279], [$order->channel, $order->status, $order->payment_method, $order->subtotal_cents, $order->discount_cents, $order->total_cents]);
        $this->assertSame('Joana Revendedora', $this->inStore(fn () => Customer::find($order->customer_id)->name));
        $this->assertSame([6279, 2], $this->inStore(fn () => [(int) Receivable::sum('amount_cents'), Receivable::count()]));
        $this->assertSame([2, 3], [$this->available('CR-P'), $this->available('CR-M')]); // 5 − 3 vendidas; M toda de volta
        $this->assertSame([2, 0], $this->inStore(fn () => [StockLevel::where('variant_id', $this->v['CR-P']->id)->value('on_hand'), StockLevel::where('variant_id', $this->v['CR-P']->id)->value('reserved')]));
        $this->assertSame(['acertada', $order->id], [$maleta->fresh()->status, $maleta->fresh()->order_id]);
        $this->post($this->url("/app/consignacao/{$maleta->id}/acerto"), ['payment' => 'pago'])->assertSessionHasErrors('status');
        $this->get($this->url("/app/consignacao/{$maleta->id}"))->assertSee('Como foi o acerto')->assertSee('pedido #'.$order->number);
    }

    public function test_maleta_de_cliente_que_devolveu_tudo_nao_gera_venda_e_aparece_no_painel_quando_atrasa(): void
    {
        $customer = $this->inStore(fn () => Customer::create(['name' => 'Bia Cliente', 'phone' => '11988887777']));
        $this->post($this->url('/app/consignacao'), ['customer_id' => $customer->id, 'kind' => 'cliente', 'commission_percent' => 50, 'sent_on' => today()->subDays(5)->toDateString(),
            'due_on' => today()->subDay()->toDateString(), 'items' => [$this->v['CR-M']->id => 2]])->assertSessionHasNoErrors();
        $maleta = $this->inStore(fn () => Consignment::where('number', 1)->sole());
        $this->assertSame(0.0, $maleta->commission_percent); // cliente não tem comissão
        // Acerto daqui a 2 dias também avisa (o próprio dia conta: a data é gravada sem hora).
        $this->post($this->url('/app/consignacao'), ['customer_id' => $customer->id, 'kind' => 'cliente', 'due_on' => today()->addDays(2)->toDateString(), 'items' => [$this->v['CR-P']->id => 1]])->assertSessionHasNoErrors();
        $this->assertSame(today()->addDays(2)->toDateString(), $this->inStore(fn () => Consignment::where('number', 2)->toBase()->value('due_on')));
        $this->get($this->url('/app'))->assertSee('Acerto da maleta #1')->assertSee('atrasado desde')->assertSee('Acerto da maleta #2');
        $this->get($this->url('/app/consignacao'))->assertSee('1 com o acerto atrasado');

        $this->post($this->url("/app/consignacao/{$maleta->id}/acerto"), ['payment' => 'pago', 'method' => 'pix'])->assertSessionHasNoErrors(); // padrão: tudo voltou
        $this->assertSame(0, $this->inStore(fn () => Order::count()));
        $this->assertSame(3, $this->available('CR-M'));
        $this->get($this->url('/app'))->assertDontSee('Acerto da maleta #1');

        // Sem peça e sem pessoa não abre.
        $this->post($this->url('/app/consignacao'), ['kind' => 'cliente', 'items' => [$this->v['CR-M']->id => 1]])->assertSessionHasErrors('customer_name');
        $this->post($this->url('/app/consignacao'), ['customer_id' => $customer->id, 'kind' => 'cliente', 'items' => [$this->v['CR-M']->id => 0]])->assertSessionHasErrors('items');
    }

    public function test_painel_das_consignacoes_recebidas_mostra_vendido_divida_e_lucro(): void
    {
        $this->inStore(function () {
            $batch = SupplyBatch::create(['supplier' => 'Miza', 'document_number' => '1945', 'received_on' => today()->subDays(10), 'closing_on' => today()->addDays(5), 'status' => 'aberta']);
            SupplyItem::create(['batch_id' => $batch->id, 'variant_id' => $this->v['CR-P']->id, 'description' => 'CALCINHA RENDA | P', 'mode' => 'consignado', 'quantity' => 5, 'document_price_cents' => 2990, 'cost_cents' => 2000]);
            SupplyItem::create(['batch_id' => $batch->id, 'variant_id' => $this->v['CR-M']->id, 'description' => 'CALCINHA RENDA | M', 'mode' => 'consignado', 'quantity' => 3, 'document_price_cents' => 2990, 'cost_cents' => 2000]);
            app(StockService::class)->sell($this->v['CR-P'], 2);
        });

        $this->get($this->url('/app/consignacao-recebida'))->assertOk()
            ->assertSee('2 de 8 peças')->assertSee('R$ 59,80') // vendido pelo preço de venda
            ->assertSee('R$ 40,00')->assertSee('R$ 19,80') // já deve e lucro
            ->assertSee('25% vendido')->assertSee('faltam 5 dia(s)')
            ->assertSee('1 peça(s) sem nenhuma venda');

        $attendant = User::factory()->create();
        Membership::create(['organization_id' => $this->store->organization_id, 'user_id' => $attendant->id, 'role' => 'attendant']);
        $this->actingAs($attendant)->get($this->url('/app/consignacao-recebida'))->assertForbidden();
    }

    public function test_maleta_de_outra_loja_nao_abre(): void
    {
        $other = User::factory()->create();
        $otherStore = app(OpenStore::class)->open($other, 'Outra Loja');
        $foreign = app(ContextStore::class)->run(ExecutionContext::forUser($otherStore->organization, $other->id), function () {
            $customer = Customer::create(['name' => 'Fulana']);

            return Consignment::create(['number' => 1, 'customer_id' => $customer->id, 'sent_on' => today()]);
        });

        $this->get($this->url("/app/consignacao/{$foreign->id}"))->assertNotFound();
        $this->post($this->url("/app/consignacao/{$foreign->id}/acerto"), ['payment' => 'pago'])->assertNotFound();
    }

    public function test_link_da_maleta_mostra_so_o_da_pessoa_sem_login_e_pode_ser_trocado(): void
    {
        $this->post($this->url('/app/consignacao'), ['customer_name' => 'Joana Revendedora', 'customer_phone' => '15999990000', 'kind' => 'revendedora', 'commission_percent' => 30,
            'due_on' => today()->addDays(7)->toDateString(), 'items' => [$this->v['CR-P']->id => 2]])->assertSessionHasNoErrors();
        $this->post($this->url('/app/consignacao'), ['customer_name' => 'Outra Pessoa', 'kind' => 'cliente', 'items' => [$this->v['CR-M']->id => 1]])->assertSessionHasNoErrors();
        [$maleta, $other] = $this->inStore(fn () => [Consignment::with('customer')->where('number', 1)->sole(), Consignment::with('customer')->where('number', 2)->sole()]);

        $page = $this->get($this->url("/app/consignacao/{$maleta->id}"))->assertOk()->assertSee('Link da maleta de Joana');
        $token = $maleta->customer->fresh()->portal_token;
        $this->assertSame(32, strlen($token));
        $this->assertStringContainsString(rawurlencode('/lingerie-da-fran/r/'.$token), $page->getContent()); // vai na lista do WhatsApp

        // Sem login, loja sem vitrine publicada: abre só com o código certo.
        auth()->logout();
        $this->assertFalse($this->store->fresh()->isPublished());
        $this->get($this->url('/lingerie-da-fran/r/'.$token))->assertOk()
            ->assertSee('Olá, Joana!')->assertSee('Maleta #1')->assertSee('R$ 59,80') // 2 × 29,90
            ->assertSee('Se vender tudo, você ganha')->assertSee('R$ 17,94') // 30% de 59,80
            ->assertDontSee('Outra Pessoa')->assertDontSee('Calcinha Renda — M')
            ->assertSee('noindex', false)->assertDontSee('Carrinho<span>', false);
        $this->get($this->url('/lingerie-da-fran/r/'.str_repeat('x', 32)))->assertNotFound();
        $this->get($this->url('/outra-loja/r/'.$token))->assertNotFound();

        // Depois do acerto (fica a receber): comissão ganha e o que falta pagar.
        $this->actingAs($this->owner);
        $item = $this->inStore(fn () => $maleta->items()->sole());
        $this->post($this->url("/app/consignacao/{$maleta->id}/acerto"), ['returns' => [$item->id => 0], 'payment' => 'receber', 'installments' => 1, 'first_due' => today()->addDays(10)->toDateString()])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get($this->url('/lingerie-da-fran/r/'.$token))->assertOk()->assertSee('Acertos anteriores')->assertSee('R$ 17,94')->assertSee('Falta pagar à loja')->assertSee('R$ 41,86');

        // Trocar o link: o antigo para de abrir.
        $this->actingAs($this->owner)->post($this->url("/app/consignacao/{$maleta->id}/novo-link"))->assertSessionHasNoErrors();
        auth()->logout();
        $this->get($this->url('/lingerie-da-fran/r/'.$token))->assertNotFound();
        $this->get($this->url('/lingerie-da-fran/r/'.$maleta->customer->fresh()->portal_token))->assertOk();
        $this->assertNull($other->customer->fresh()->portal_token); // só quem teve a maleta aberta na tela ganhou link
    }
}
