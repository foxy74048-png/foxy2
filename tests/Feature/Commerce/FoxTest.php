<?php

namespace Tests\Feature\Commerce;

use App\Ai\Plans\AiSubscription;
use App\Ai\Providers\ProviderUnavailable;
use App\Commerce\Assistant\CommerceFox;
use App\Commerce\Catalog\CatalogService;
use App\Commerce\Catalog\Variant;
use App\Commerce\Orders\OrderService;
use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Models\User;
use App\Platform\Billing\Subscription;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Ai\InteractsWithFoxyAi;
use Tests\TestCase;

/**
 * Foxy Commerce, fatia 10 (docs/09, seção 9): a raposa no app do lojista, pela API da Foxy AI, com as consultas
 * da loja respeitando o papel de quem pergunta. FakeProvider no lugar do modelo.
 */
class FoxTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->setUpFakeAi();
        $this->owner = User::factory()->create();
        $this->store = app(OpenStore::class)->open($this->owner, 'Moda da Ana');
        app(ContextStore::class)->run(ExecutionContext::forUser($this->store->organization, $this->owner->id), function () {
            app(CatalogService::class)->create(['name' => 'Boné', 'price_cents' => 5000, 'cost_cents' => 2000, 'sku' => 'BONE'], [], []);
            app(OrderService::class)->place(['channel' => 'whatsapp', 'paid' => true, 'items' => [['variant' => Variant::sole(), 'quantity' => 2]]]);
        });
        $this->artisan('commerce:raposa')->assertSuccessful();
    }

    private function url(string $path): string
    {
        return 'http://'.config('foxy.hosts.commerce').$path;
    }

    private function ask(string $text, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->postJson($this->url('/app/raposa/mensagem'), ['text' => $text, 'page' => 'Painel']);
    }

    public function test_janela_da_raposa_aparece_quando_publicada(): void
    {
        $this->assertTrue(CommerceFox::isReady());
        $this->actingAs($this->owner)->get($this->url('/app'))->assertOk()->assertSee('FoxyAssistant.mount', false)->assertSee('Quanto vendi este mês');
    }

    public function test_dono_pergunta_sobre_vendas_e_a_raposa_consulta_a_loja(): void
    {
        $today = now()->format('Y-m-d');
        $this->ai->pushToolUse([['id' => 't1', 'name' => 'vendas_resumo', 'input' => ['inicio' => now()->startOfMonth()->format('Y-m-d'), 'fim' => $today]]])
            ->pushText('Você vendeu **R$ 100,00** este mês, tudo pelo WhatsApp.');

        $response = $this->ask('Quanto vendi este mês?')->assertOk();
        $this->assertSame('Você vendeu **R$ 100,00** este mês, tudo pelo WhatsApp.', $response->json('reply'));
        $this->assertSame(['Financeiro'], $response->json('tools_used'));

        // O resultado da consulta que foi para a IA tem os números da loja.
        $sent = json_encode($this->ai->lastRequest()->messages, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('R$ 100,00', $sent);
        $this->assertStringContainsString('WhatsApp', $sent);

        // A loja ganhou um plano de perguntas na Foxy AI.
        $this->assertSame(config('commerce.ai_plan'), AiSubscription::withoutGlobalScopes()->where('organization_id', $this->store->organization_id)->value('plan'));
    }

    public function test_atendente_nao_recebe_as_consultas_financeiras(): void
    {
        $attendant = User::factory()->create();
        Membership::create(['organization_id' => $this->store->organization_id, 'user_id' => $attendant->id, 'role' => 'attendant']);
        $this->ai->pushText('Posso ajudar com pedidos e estoque.');

        $this->ask('Quanto a loja faturou?', $attendant)->assertOk()->assertJsonPath('reply', 'Posso ajudar com pedidos e estoque.');

        $tools = collect($this->ai->lastRequest()->tools)->pluck('name')->all();
        $this->assertContains('pedidos_pendentes', $tools);
        $this->assertNotContains('vendas_resumo', $tools);
        $this->assertNotContains('vendas_mais_vendidos', $tools);
    }

    public function test_raposa_responde_mesmo_com_a_loja_so_para_consulta(): void
    {
        Subscription::where('organization_id', $this->store->organization_id)->update(['trial_ends_at' => now()->subDay()]);
        $this->ai->pushText('A loja está só para consulta porque o teste acabou.');

        $this->ask('Por que não consigo cadastrar produto?')->assertOk()->assertJsonPath('reply', 'A loja está só para consulta porque o teste acabou.');
    }

    public function test_nova_conversa_e_raposa_indisponivel(): void
    {
        $this->actingAs($this->owner)->postJson($this->url('/app/raposa/nova'))->assertOk()->assertJsonStructure(['assistant' => ['name']]);

        $this->ai->push(new ProviderUnavailable('fora do ar'));
        $this->ask('Oi')->assertOk()->assertJsonPath('reply', null)->assertJsonStructure(['error' => ['message']]);
    }
}
