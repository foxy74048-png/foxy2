<?php

namespace Tests\Feature\Finance;

use App\Ai\Plans\AiSubscription;
use App\Ai\Providers\ModelResponse;
use App\Finance\Accounts\Account;
use App\Finance\Assistant\AiNote;
use App\Finance\Assistant\FinanceFox;
use App\Finance\Categories\Category;
use App\Finance\Statements\StatementImporter;
use App\Finance\Statements\StatementLine;
use App\Finance\Transactions\TransactionService;
use App\Finance\Wallets\OpenWallet;
use App\Finance\Wallets\Wallet;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Ai\InteractsWithFoxyAi;
use Tests\TestCase;

/**
 * Foxy Finanças, fatia 8: a raposa — conversa com consultas da carteira (respeitando o papel), "Sugerir com a
 * raposa" no extrato (só a descrição vai) e as dicas do mês (sem número inventado). FakeProvider no lugar do modelo.
 */
class FoxTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    private User $ana;

    private Wallet $wallet;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 10:00:00');
        Storage::fake();
        $this->setUpFakeAi();
        $this->ana = User::factory()->create();
        $this->wallet = app(OpenWallet::class)->open($this->ana, 'Pessoal');
        $this->bank = $this->in(fn () => Account::create(['kind' => 'corrente', 'name' => 'Nubank', 'opening_balance_cents' => 500000]));
        $this->in(function () {
            $t = app(TransactionService::class);
            $t->create(['kind' => 'recebimento', 'amount_cents' => 400000, 'date' => '2026-11-05', 'description' => 'Salário', 'account_id' => $this->bank->id, 'category_id' => $this->cat('salario')]);
            $t->create(['kind' => 'gasto', 'amount_cents' => 12000, 'date' => '2026-11-10', 'description' => 'IFOOD', 'account_id' => $this->bank->id, 'category_id' => $this->cat('delivery')]);
        });
        $this->artisan('financas:raposa')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(string $path): string
    {
        return 'http://'.config('foxy.hosts.financas').$path;
    }

    private function in(callable $fn)
    {
        return app(ContextStore::class)->run(ExecutionContext::forUser($this->wallet->organization, $this->ana->id), $fn);
    }

    private function cat(string $key): ?string
    {
        return Category::where('system_key', $key)->value('id');
    }

    private function modelJson(array $data): ModelResponse
    {
        return new ModelResponse('claude-haiku-5-5', 'end_turn', [['type' => 'text', 'text' => json_encode($data)]], 400, 60);
    }

    private function viewer(): User
    {
        $viewer = User::factory()->create();
        Membership::create(['organization_id' => $this->wallet->organization_id, 'user_id' => $viewer->id, 'role' => 'viewer']);

        return $viewer;
    }

    private function ask(string $text, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->ana)->postJson($this->url('/app/raposa/mensagem'), ['text' => $text, 'page' => 'Início']);
    }

    public function test_janela_da_raposa_aparece_quando_publicada(): void
    {
        $this->assertTrue(FinanceFox::isReady());
        $this->actingAs($this->ana)->get($this->url('/app'))->assertOk()->assertSee('FoxyAssistant.mount', false)->assertSee('Quanto gastei com delivery este ano?');
    }

    public function test_pergunta_e_a_raposa_consulta_a_carteira_com_os_valores_prontos(): void
    {
        $this->ai->pushToolUse([['id' => 't1', 'name' => 'gastos_por', 'input' => ['agrupar' => 'categoria', 'inicio' => '2026-11-01', 'fim' => '2026-11-30']]])
            ->pushText('Este mês você gastou **R$ 120,00** com delivery.');

        $response = $this->ask('Quanto gastei com delivery?')->assertOk();
        $this->assertSame('Este mês você gastou **R$ 120,00** com delivery.', $response->json('reply'));
        $this->assertSame(['Lançamentos'], $response->json('tools_used'));

        $sent = json_encode($this->ai->lastRequest()->messages, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('R$ 120,00', $sent);
        $this->assertStringContainsString('Delivery', $sent);
        $this->assertSame(config('finance.ai_plan'), AiSubscription::withoutGlobalScopes()->where('organization_id', $this->wallet->organization_id)->value('plan'));
    }

    public function test_carteira_pessoal_nao_oferece_socios_e_quem_so_ve_nao_pede_sugestao(): void
    {
        $this->ai->pushText('Oi!');
        $this->ask('Oi')->assertOk();
        $tools = collect($this->ai->lastRequest()->tools)->pluck('name')->all();
        $this->assertContains('resumo_periodo', $tools);
        $this->assertNotContains('socios_mes', $tools);

        $this->actingAs($this->viewer())->post($this->url('/app/raposa/categorias'))->assertForbidden();
    }

    public function test_sugerir_com_a_raposa_manda_so_a_descricao(): void
    {
        $this->in(fn () => app(StatementImporter::class)->import($this->bank, 'ofx', 'x.ofx', [
            ['date' => '2026-11-12', 'time' => null, 'description' => 'PADARIA DO ZE 12345678', 'amount' => -18.5, 'external_id' => 'A1'],
            ['date' => '2026-11-13', 'time' => null, 'description' => 'LOJA XYZ', 'amount' => -77.3, 'external_id' => 'A2'],
        ]));
        $lines = $this->in(fn () => StatementLine::orderBy('date')->get());
        $this->assertNull($lines[0]->suggested_category_id);
        $mercado = $this->in(fn () => $this->cat('mercado') ?? Category::where('kind', 'gasto')->value('id'));
        $salario = $this->in(fn () => $this->cat('salario'));

        $this->actingAs($this->ana)->get($this->url('/app/extratos'))->assertSee('Sugerir com a raposa');

        // linhas em ordem de data decrescente: índice 0 = LOJA XYZ, 1 = PADARIA. A de receita é recusada (tipo errado).
        $this->ai->push($this->modelJson(['items' => [['index' => 1, 'category_id' => $mercado], ['index' => 0, 'category_id' => $salario]]]));
        $this->actingAs($this->ana)->post($this->url('/app/raposa/categorias'))->assertSessionHas('status', fn ($s) => str_starts_with($s, '1 de 2'));

        $padaria = $this->in(fn () => StatementLine::where('description', 'PADARIA DO ZE 12345678')->first());
        $this->assertSame([$mercado, 'raposa'], [$padaria->suggested_category_id, $padaria->suggestion_origin]);
        $this->assertNull($this->in(fn () => StatementLine::where('description', 'LOJA XYZ')->value('suggested_category_id')));

        $sent = json_encode($this->ai->lastRequest()->messages, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('PADARIA DO ZE', $sent);
        $this->assertStringNotContainsString('12345678', $sent);
        $this->assertStringNotContainsString('18,5', $sent);
        $this->assertStringNotContainsString('18.5', $sent);
        $this->assertStringNotContainsString('Nubank', $sent);
        $this->assertStringNotContainsString('2026-11-12', $sent);
    }

    public function test_dicas_do_mes_ficam_guardadas_e_frase_com_valor_inventado_cai(): void
    {
        $this->ai->push($this->modelJson([
            'sentences' => ['Você gastou R$ 120,00 com delivery.', 'Dá para guardar R$ 9.999,00 por mês.', 'Sobrou bastante este mês.'],
            'suggestion' => 'Separe uma parte da sobra logo no dia do salário.',
        ]));

        $this->actingAs($this->ana)->post($this->url('/app/raposa/dicas'), ['mes' => '2026-11'])->assertSessionHas('status');

        $note = $this->in(fn () => AiNote::where('kind', 'tips')->where('month', '2026-11')->first());
        $this->assertSame(['Você gastou R$ 120,00 com delivery.', 'Sobrou bastante este mês.'], $note->content['sentences']);
        $this->assertSame('Separe uma parte da sobra logo no dia do salário.', $note->content['suggestion']);

        $sent = json_encode($this->ai->lastRequest()->messages, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('IFOOD', $sent);
        $this->assertStringNotContainsString('Nubank', $sent);

        $this->actingAs($this->ana)->get($this->url('/app/painel?mes=2026-11'))->assertOk()
            ->assertSee('A raposa sobre o seu mês')->assertSee('Sobrou bastante este mês.')->assertDontSee('R$ 9.999,00')->assertSee('Escrever de novo');
    }

    public function test_sem_credito_avisa_com_mensagem_amigavel(): void
    {
        config(['finance.ai_plan' => 'ia-0']);

        $this->actingAs($this->ana)->post($this->url('/app/raposa/dicas'), ['mes' => '2026-11'])
            ->assertSessionHasErrors(['fox' => 'Os créditos da raposa deste mês acabaram. Eles voltam no próximo mês.']);
        $this->assertSame([], $this->ai->requests);
        $this->assertSame(0, $this->in(fn () => AiNote::count()));
    }
}
