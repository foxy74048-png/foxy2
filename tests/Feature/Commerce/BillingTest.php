<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Commerce;
use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Models\User;
use App\Platform\Billing\BillingGateway;
use App\Platform\Billing\FakeBillingGateway;
use App\Platform\Billing\Subscription;
use App\Platform\Memberships\Membership;
use App\Platform\Products\ProductAccount;
use App\Platform\Products\ProductCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Foxy Commerce, fatia 2 (docs/09): assinatura pelo Stripe (Checkout, portal e webhook), teste grátis, só leitura,
 * limites do plano e ações do Foxy Admin nas lojas.
 */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    private FakeBillingGateway $stripe;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeBillingGateway;
        $this->app->instance(BillingGateway::class, $this->stripe);
        config([
            'commerce.plans.inicial.stripe_price' => 'price_inicial',
            'commerce.plans.profissional.stripe_price' => 'price_pro',
        ]);

        $this->owner = User::factory()->create(['name' => 'Ana', 'email' => 'ana@loja.test']);
        $this->store = app(OpenStore::class)->open($this->owner, 'Moda da Ana');
    }

    private function url(string $path): string
    {
        return 'http://'.config('foxy.hosts.commerce').$path;
    }

    private function subscription(): Subscription
    {
        return Subscription::where('organization_id', $this->store->organization_id)->firstOrFail();
    }

    private function webhook(string $id, string $type, array $object, string $signature = 'valida')
    {
        return $this->call('POST', $this->url('/stripe/webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['id' => $id, 'type' => $type, 'data' => ['object' => $object]]));
    }

    private function stripeSubscription(string $status, string $price = 'price_pro', array $extra = []): array
    {
        return array_merge([
            'id' => 'sub_1', 'customer' => 'cus_1', 'status' => $status, 'cancel_at_period_end' => false,
            'metadata' => ['subscription_ref' => $this->subscription()->id],
            'items' => ['data' => [['price' => ['id' => $price], 'current_period_end' => now()->addMonth()->getTimestamp()]]],
        ], $extra);
    }

    /** Assinatura confirmada pelo Stripe: checkout concluído e assinatura ativa. */
    private function pay(): void
    {
        $this->stripe->subscriptions['sub_1'] = $this->stripeSubscription('active');
        $this->webhook('evt_checkout', 'checkout.session.completed', [
            'id' => 'cs_1', 'mode' => 'subscription', 'customer' => 'cus_1', 'subscription' => 'sub_1', 'client_reference_id' => $this->subscription()->id,
        ])->assertOk();
    }

    private function expireTrial(): void
    {
        $this->subscription()->update(['trial_ends_at' => now()->subMinute()]);
    }

    public function test_loja_nova_comeca_em_teste_gratis_de_14_dias(): void
    {
        $subscription = $this->subscription();
        $this->assertSame('trialing', $subscription->status);
        $this->assertTrue($subscription->trial_ends_at->isSameDay(now()->addDays(14)));
        $this->assertSame(Subscription::FULL, $subscription->access());

        $this->actingAs($this->owner)->get($this->url('/app'))->assertOk()->assertSee('Teste grátis até');
        $this->assertSame('trial', ProductAccount::first()->status);
    }

    public function test_assinar_leva_ao_checkout_do_stripe_com_os_dados_da_loja(): void
    {
        $this->actingAs($this->owner)->get($this->url('/app/assinatura'))->assertOk()->assertSee('Profissional')->assertSee('R$ 149,00/mês');

        $this->post($this->url('/app/assinatura/assinar'), ['plan' => 'profissional'])->assertRedirect('https://checkout.stripe.test/c/1');

        $params = $this->stripe->checkouts[0];
        $this->assertSame('subscription', $params['mode']);
        $this->assertSame('price_pro', $params['line_items'][0]['price']);
        $this->assertSame($this->subscription()->id, $params['client_reference_id']);
        $this->assertSame('ana@loja.test', $params['customer_email']);
        // Assinando no teste, a cobrança só começa quando o teste acaba.
        $this->assertSame($this->subscription()->trial_ends_at->getTimestamp(), $params['subscription_data']['trial_end']);
    }

    public function test_plano_sem_preco_no_stripe_nao_abre_o_checkout(): void
    {
        $this->actingAs($this->owner)->post($this->url('/app/assinatura/assinar'), ['plan' => 'completo'])
            ->assertRedirect($this->url('/app/assinatura'))->assertSessionHasErrors('billing');
        $this->assertSame([], $this->stripe->checkouts);
    }

    public function test_webhook_confirma_a_assinatura_e_o_admin_ve_o_mrr(): void
    {
        $this->pay();

        $subscription = $this->subscription();
        $this->assertSame('active', $subscription->status);
        $this->assertSame('profissional', $subscription->plan);
        $this->assertSame('cus_1', $subscription->stripe_customer_id);
        $this->assertTrue($subscription->current_period_end->isSameDay(now()->addMonth()));

        $account = ProductAccount::where('integration_id', Commerce::integration()->id)->first();
        $this->assertSame('active', $account->status);
        $this->assertSame('Profissional', $account->plan);
        $this->assertSame('149.00', $account->mrr);

        // Depois de assinar, o botão leva ao portal do Stripe.
        $this->actingAs($this->owner)->post($this->url('/app/assinatura/gerenciar'))->assertRedirect('https://billing.stripe.test/p/cus_1');
        $this->post($this->url('/app/assinatura/assinar'), ['plan' => 'inicial'])->assertSessionHasErrors('billing');
    }

    public function test_quem_assina_durante_o_teste_nao_ve_os_planos_de_novo(): void
    {
        // O Stripe guarda o resto do teste: a assinatura fica "trialing" até a primeira cobrança.
        $this->stripe->subscriptions['sub_1'] = $this->stripeSubscription('trialing');
        $this->webhook('evt_checkout', 'checkout.session.completed', [
            'id' => 'cs_1', 'mode' => 'subscription', 'customer' => 'cus_1', 'subscription' => 'sub_1', 'client_reference_id' => $this->subscription()->id,
        ])->assertOk();

        $this->actingAs($this->owner)->get($this->url('/app/assinatura'))->assertOk()
            ->assertSee('Plano <strong>Profissional</strong>', false)->assertSee('A primeira cobrança acontece')->assertDontSee('Assinar o Inicial');
        $this->post($this->url('/app/assinatura/assinar'), ['plan' => 'inicial'])->assertSessionHasErrors('billing');
        $this->assertSame([], $this->stripe->checkouts);

        $account = ProductAccount::first();
        $this->assertSame('trial', $account->status);
        $this->assertStringContainsString('assinado', $account->plan);
        $this->assertSame('0.00', $account->mrr);
    }

    public function test_webhook_com_assinatura_falsa_e_ignorado(): void
    {
        $this->webhook('evt_x', 'customer.subscription.updated', $this->stripeSubscription('active'), 'falsa')->assertStatus(400);
        $this->assertSame('trialing', $this->subscription()->status);

        // Eventos do Foxy Studio (mesma conta do Stripe) passam sem mexer em nada.
        $this->webhook('evt_studio', 'checkout.session.completed', ['id' => 'cs_studio', 'mode' => 'subscription', 'customer' => 'cus_studio', 'subscription' => 'sub_studio', 'metadata' => ['user_id' => '7']])->assertOk();
        $this->webhook('evt_studio2', 'customer.subscription.updated', ['id' => 'sub_studio', 'customer' => 'cus_studio', 'status' => 'active', 'items' => ['data' => []]])->assertOk();
        $this->assertSame('trialing', $this->subscription()->status);
        $this->assertNull($this->subscription()->stripe_subscription_id);
    }

    public function test_o_mesmo_evento_so_e_processado_uma_vez(): void
    {
        $this->pay();
        $this->webhook('evt_cancel', 'customer.subscription.deleted', $this->stripeSubscription('canceled'))->assertOk();
        $this->assertSame('canceled', $this->subscription()->status);

        // Reenvio de um evento antigo não volta a assinatura para "ativa".
        $this->webhook('evt_checkout', 'checkout.session.completed', [
            'id' => 'cs_1', 'mode' => 'subscription', 'customer' => 'cus_1', 'subscription' => 'sub_1', 'client_reference_id' => $this->subscription()->id,
        ])->assertOk();
        $this->assertSame('canceled', $this->subscription()->status);
    }

    public function test_teste_vencido_deixa_a_loja_so_para_consulta(): void
    {
        $this->expireTrial();
        Notification::fake();

        $this->actingAs($this->owner)->get($this->url('/app'))->assertOk()->assertSee('só para consulta');
        $this->get($this->url('/app/equipe'))->assertOk();
        $this->post($this->url('/app/equipe/convites'), ['email' => 'bruno@loja.test', 'role' => 'attendant'])
            ->assertRedirect($this->url('/app/assinatura'))->assertSessionHasErrors('billing');
        Notification::assertNothingSent();

        // Assinar continua possível.
        $this->post($this->url('/app/assinatura/assinar'), ['plan' => 'inicial'])->assertRedirect('https://checkout.stripe.test/c/1');
        $this->assertArrayNotHasKey('trial_end', $this->stripe->checkouts[0]['subscription_data']);

        $this->artisan('commerce:contas')->assertSuccessful();
        $this->assertSame('expired', ProductAccount::first()->status);
    }

    public function test_assinatura_cancelada_fica_so_para_consulta_e_pagamento_atrasado_avisa(): void
    {
        $this->expireTrial();
        $this->pay();
        $this->actingAs($this->owner)->get($this->url('/app'))->assertOk()->assertDontSee('só para consulta');

        $this->webhook('evt_late', 'customer.subscription.updated', $this->stripeSubscription('past_due'))->assertOk();
        $this->get($this->url('/app'))->assertSee('Não conseguimos cobrar');
        $this->assertSame('Pagamento atrasado', ProductAccount::first()->problems[0]['label']);

        $this->webhook('evt_gone', 'customer.subscription.deleted', $this->stripeSubscription('canceled'))->assertOk();
        $this->get($this->url('/app'))->assertSee('só para consulta');
        $this->assertSame('canceled', ProductAccount::first()->status);
    }

    public function test_so_o_dono_ve_a_assinatura(): void
    {
        $manager = User::factory()->create();
        Membership::create(['organization_id' => $this->store->organization_id, 'user_id' => $manager->id, 'role' => 'manager']);

        $this->actingAs($manager)->get($this->url('/app/assinatura'))->assertForbidden();
        $this->actingAs($manager)->post($this->url('/app/assinatura/assinar'), ['plan' => 'inicial'])->assertForbidden();
        $this->assertSame([], $this->stripe->checkouts);
    }

    public function test_convites_respeitam_o_limite_de_pessoas_do_plano(): void
    {
        Notification::fake();
        $this->expireTrial();
        $this->pay(); // Profissional pago: até 5 pessoas
        config(['commerce.plans.profissional.limits.users' => 3]);

        $this->actingAs($this->owner)->post($this->url('/app/equipe/convites'), ['email' => 'b@loja.test', 'role' => 'attendant'])->assertSessionHasNoErrors();
        $this->post($this->url('/app/equipe/convites'), ['email' => 'c@loja.test', 'role' => 'attendant'])->assertSessionHasNoErrors();
        $this->post($this->url('/app/equipe/convites'), ['email' => 'd@loja.test', 'role' => 'attendant'])->assertSessionHasErrors('email');
        // Reenviar para quem já foi convidado não conta duas vezes.
        $this->post($this->url('/app/equipe/convites'), ['email' => 'c@loja.test', 'role' => 'manager'])->assertSessionHasNoErrors();
    }

    private function adminCommand(string $type, array $payload = []): ProductCommand
    {
        $command = ProductCommand::create([
            'integration_id' => Commerce::integration()->id,
            'product_account_id' => ProductAccount::first()->id,
            'type' => $type,
            'payload' => $payload,
        ]);
        $this->artisan('commerce:comandos')->assertSuccessful();

        return $command->fresh();
    }

    public function test_foxy_admin_estende_o_teste_e_nao_mexe_em_quem_paga(): void
    {
        $this->expireTrial();
        $command = $this->adminCommand('account.extend_trial', ['days' => 10]);

        $this->assertSame('feito', $command->status);
        $this->assertTrue($this->subscription()->trial_ends_at->isSameDay(now()->addDays(10)));
        $this->assertSame('trial', ProductAccount::first()->status);
        $this->actingAs($this->owner)->get($this->url('/app'))->assertDontSee('só para consulta');

        $this->pay();
        $this->assertSame('falhou', $this->adminCommand('account.extend_trial', ['days' => 5])->status);
    }

    public function test_foxy_admin_suspende_reativa_e_faz_parceiro_fundador(): void
    {
        $this->assertSame('feito', $this->adminCommand('account.suspend', ['reason' => 'teste'])->status);
        $this->actingAs($this->owner)->get($this->url('/app'))->assertStatus(403)->assertSee('Loja suspensa');
        $this->assertSame('suspended', ProductAccount::first()->status);

        $this->adminCommand('account.unsuspend');
        $this->get($this->url('/app'))->assertOk();

        $this->expireTrial();
        $this->assertSame('feito', $this->adminCommand('account.founder', ['active' => true])->status);
        $this->get($this->url('/app'))->assertOk()->assertSee('Parceiro Fundador')->assertDontSee('só para consulta');
        $this->assertSame('founder', ProductAccount::first()->status);

        $this->assertSame('falhou', $this->adminCommand('account.fox_credits', ['credits' => 100])->status);
    }
}
