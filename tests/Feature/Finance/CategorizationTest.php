<?php

namespace Tests\Feature\Finance;

use App\Finance\Accounts\Account;
use App\Finance\Categories\Categorizer;
use App\Finance\Categories\Category;
use App\Finance\Categories\CategoryRule;
use App\Finance\Statements\StatementImporter;
use App\Finance\Statements\StatementLine;
use App\Finance\Transactions\Transaction;
use App\Finance\Transactions\TransactionService;
use App\Finance\Wallets\OpenWallet;
use App\Finance\Wallets\Wallet;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Foxy Finanças, fatia 4 (docs/12, seção 6): categoria automática sem IA — regra da carteira, a categoria da
 * última vez, a lista pronta de lojas — e o "sempre que aparecer" ao lançar ou editar.
 */
class CategorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Wallet $wallet;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 10:00:00');
        $this->ana = User::factory()->create();
        $this->wallet = app(OpenWallet::class)->open($this->ana, 'Pessoal');
        $this->bank = $this->in(fn () => Account::create(['kind' => 'corrente', 'name' => 'Nubank']));
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

    private function in(callable $fn, ?Wallet $wallet = null)
    {
        $wallet ??= $this->wallet;

        return app(ContextStore::class)->run(ExecutionContext::forUser($wallet->organization, $this->ana->id), $fn);
    }

    private function key(string $systemKey, ?Wallet $wallet = null): string
    {
        return $this->in(fn () => Category::where('system_key', $systemKey)->value('id'), $wallet);
    }

    /** Importa linhas no banco (como se viessem do OFX) e devolve [descrição => linha]. */
    private function import(array $rows, ?Wallet $wallet = null, ?Account $account = null)
    {
        return $this->in(function () use ($rows, $account) {
            $i = 0;
            app(StatementImporter::class)->import($account ?? $this->bank, 'ofx', 'x.ofx', array_map(fn ($r) => [
                'date' => '2026-11-10', 'time' => null, 'description' => $r[0], 'amount' => $r[1], 'external_id' => 'F'.$i++.uniqid(),
            ], $rows));

            return StatementLine::get()->keyBy('description');
        }, $wallet);
    }

    public function test_normaliza_e_sugere_as_palavras_da_regra(): void
    {
        $this->assertSame('ifood pedido 77812', Categorizer::normalize('IFOOD *Pédido 77812'));
        $this->assertTrue(Categorizer::contains('pix drogaria sao paulo', 'drogaria sao'));
        $this->assertFalse(Categorizer::contains('metropole hotel', 'metro'));
        $this->assertSame('drogaria sao', Categorizer::defaultPattern('Pix enviado - Drogaria São Paulo'));
        $this->assertSame('ifood pedido', Categorizer::defaultPattern('IFOOD *PEDIDO 77812'));
        $this->assertSame('padaria', Categorizer::defaultPattern('PADARIA'));
    }

    public function test_lista_pronta_de_lojas_conhecidas(): void
    {
        $lines = $this->import([
            ['IFOOD *PEDIDO 9931', -52.0], ['UBER *TRIP HELP.UBER.COM', -23.5], ['ENEL DISTRIBUICAO SP', -189.0],
            ['PIX ENVIADO DROGASIL 123', -40.0], ['NETFLIX.COM', -55.9], ['AMAZON PRIME CANAIS', -19.9], ['Fulano de Tal', -10.0],
        ]);

        $this->assertSame([$this->key('delivery'), 'lista'], [$lines['IFOOD *PEDIDO 9931']->suggested_category_id, $lines['IFOOD *PEDIDO 9931']->suggestion_origin]);
        $this->assertSame($this->key('app_transporte'), $lines['UBER *TRIP HELP.UBER.COM']->suggested_category_id);
        $this->assertSame($this->key('luz'), $lines['ENEL DISTRIBUICAO SP']->suggested_category_id);
        $this->assertSame($this->key('farmacia'), $lines['PIX ENVIADO DROGASIL 123']->suggested_category_id);
        // A mais longa vence: "amazon prime" é streaming, não "amazon" (compras).
        $this->assertSame($this->key('streaming'), $lines['AMAZON PRIME CANAIS']->suggested_category_id);
        // Na dúvida, sem sugestão.
        $this->assertNull($lines['Fulano de Tal']->suggested_category_id);

        $this->actingAs($this->ana)->get($this->url('/app/extratos'))->assertSee('categoria loja conhecida');
    }

    public function test_lista_so_sugere_categoria_que_existe_e_esta_visivel_na_carteira(): void
    {
        $business = app(OpenWallet::class)->open($this->ana, 'Ateliê', Wallet::BUSINESS);
        $card = $this->in(fn () => Account::create(['kind' => 'corrente', 'name' => 'PJ']), $business);
        $lines = $this->import([['PGMEI DAS SIMPLES', -75.6], ['IFOOD', -30.0]], $business, $card);
        $this->assertSame($this->key('das', $business), $lines['PGMEI DAS SIMPLES']->suggested_category_id);
        $this->assertNull($lines['IFOOD']->suggested_category_id, 'Carteira do negócio não tem "Delivery".');

        // Na pessoal, maquininha não existe; e categoria escondida não é sugerida.
        $this->in(fn () => Category::where('system_key', 'streaming')->update(['hidden_at' => now()]));
        $lines = $this->import([['STONE PAGAMENTOS', -10.0], ['NETFLIX.COM', -55.9]]);
        $this->assertNull($lines['STONE PAGAMENTOS']->suggested_category_id);
        $this->assertNull($lines['NETFLIX.COM']->suggested_category_id);
    }

    public function test_regra_da_carteira_vence_a_ultima_vez_e_a_lista(): void
    {
        $restaurant = $this->key('restaurante');
        $this->in(function () use ($restaurant) {
            app(TransactionService::class)->create(['kind' => 'gasto', 'amount_cents' => 100, 'date' => '2026-10-01', 'description' => 'IFOOD *PEDIDO 1', 'account_id' => $this->bank->id, 'category_id' => $this->key('lanche')]);
            CategoryRule::create(['pattern' => 'ifood', 'kind' => 'gasto', 'category_id' => $restaurant]);
            CategoryRule::create(['pattern' => 'ifood mercado', 'kind' => 'gasto', 'category_id' => $this->key('mercado')]);
        });

        $lines = $this->import([['IFOOD *PEDIDO 2', -30.0], ['IFOOD MERCADO 3', -80.0], ['IFOOD ESTORNO', 30.0]]);

        $this->assertSame([$restaurant, 'regra'], [$lines['IFOOD *PEDIDO 2']->suggested_category_id, $lines['IFOOD *PEDIDO 2']->suggestion_origin]);
        $this->assertSame($this->key('mercado'), $lines['IFOOD MERCADO 3']->suggested_category_id, 'A regra mais longa vence.');
        $this->assertNull($lines['IFOOD ESTORNO']->suggested_category_id, 'Regra de gasto não vale para recebimento.');
        $this->assertSame(1, $this->in(fn () => CategoryRule::where('pattern', 'ifood')->value('hits')));
    }

    public function test_sempre_que_aparecer_no_extrato_cria_a_regra_e_arruma_as_parecidas(): void
    {
        $lines = $this->import([['PADARIA PAO QUENTE 01', -12.0], ['PADARIA PAO QUENTE 02', -8.5], ['OUTRA COISA', -5.0]]);
        $bakery = $this->key('lanche');

        $this->actingAs($this->ana)->post($this->url("/app/extratos/linhas/{$lines['PADARIA PAO QUENTE 01']->id}/lancar"), [
            'category_id' => $bakery, 'remember' => 1, 'pattern' => 'Padaria Pão Quente',
        ])->assertSessionHas('status', fn ($s) => str_contains($s, '"padaria pao quente" vira Lanche e café') && str_contains($s, '1 linha(s) parecida(s)'));

        $this->assertSame([$bakery, 'regra'], [$lines['PADARIA PAO QUENTE 02']->fresh()->suggested_category_id, $lines['PADARIA PAO QUENTE 02']->fresh()->suggestion_origin]);
        $this->assertNull($lines['OUTRA COISA']->fresh()->suggested_category_id);

        // Próximo extrato: já vem com a categoria.
        $next = $this->import([['PADARIA PAO QUENTE 03', -9.0]]);
        $this->assertSame($bakery, $next['PADARIA PAO QUENTE 03']->suggested_category_id);

        // Sem marcar, nada de regra.
        $this->actingAs($this->ana)->post($this->url("/app/extratos/linhas/{$lines['OUTRA COISA']->id}/lancar"), ['category_id' => $this->key('compras'), 'pattern' => 'outra coisa']);
        $this->assertSame(1, $this->in(fn () => CategoryRule::count()));
    }

    public function test_lancar_sem_categoria_usa_a_automatica_e_avisa(): void
    {
        $this->actingAs($this->ana)->post($this->url('/app/lancamentos'), ['kind' => 'gasto', 'amount' => '23,50', 'description' => 'Uber pro trabalho', 'account_id' => $this->bank->id, 'date' => '2026-11-19'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Categoria "Uber / 99 / táxi" escolhida loja conhecida'));
        $this->assertSame($this->key('app_transporte'), $this->in(fn () => Transaction::value('category_id')));

        // Escolheu a categoria: vale a escolhida. Transferência não tem categoria.
        $this->actingAs($this->ana)->post($this->url('/app/lancamentos'), ['kind' => 'gasto', 'amount' => '10', 'description' => 'Uber eats', 'account_id' => $this->bank->id, 'date' => '2026-11-19', 'category_id' => $this->key('delivery')]);
        $this->assertSame($this->key('delivery'), $this->in(fn () => Transaction::where('description', 'Uber eats')->value('category_id')));
    }

    public function test_editar_com_sempre_que_aparecer_arruma_os_parecidos_sem_categoria(): void
    {
        $this->in(function () {
            $svc = app(TransactionService::class);
            foreach (['Mensalidade Inglês Wizard nov', 'Mensalidade Inglês Wizard out', 'Mensalidade natação'] as $d) {
                $svc->create(['kind' => 'gasto', 'amount_cents' => 30000, 'date' => '2026-11-05', 'description' => $d, 'account_id' => $this->bank->id]);
            }
        });
        $first = $this->in(fn () => Transaction::where('description', 'Mensalidade Inglês Wizard nov')->firstOrFail());
        $courses = $this->key('cursos');

        $this->actingAs($this->ana)->get($this->url("/app/lancamentos/{$first->id}"))->assertSee('Sempre usar esta categoria');
        $this->actingAs($this->ana)->post($this->url("/app/lancamentos/{$first->id}"), [
            'amount' => '300', 'description' => $first->description, 'account_id' => $this->bank->id, 'date' => '2026-11-05',
            'category_id' => $courses, 'remember' => 1, 'pattern' => 'ingles wizard', 'apply_similar' => 1,
        ])->assertSessionHas('status', fn ($s) => str_contains($s, '1 lançamento(s) parecido(s)'));

        $this->assertSame($courses, $this->in(fn () => Transaction::where('description', 'Mensalidade Inglês Wizard out')->value('category_id')));
        $this->assertNull($this->in(fn () => Transaction::where('description', 'Mensalidade natação')->value('category_id')));
    }

    public function test_regras_na_tela_de_categorias(): void
    {
        $this->actingAs($this->ana)->post($this->url('/app/regras'), ['pattern' => 'Escola Pequeno Príncipe', 'category_id' => $this->key('escola')])->assertSessionHasNoErrors();
        $rule = $this->in(fn () => CategoryRule::firstOrFail());
        $this->assertSame('escola pequeno principe', $rule->pattern);

        $this->actingAs($this->ana)->get($this->url('/app/categorias'))->assertSee('Regras automáticas')->assertSee('escola pequeno principe');
        $this->actingAs($this->ana)->get($this->url('/app/categorias?exportar=planilha'))->assertOk();

        // Mesmo padrão de novo: troca a categoria, não duplica.
        $this->actingAs($this->ana)->post($this->url('/app/regras'), ['pattern' => 'escola pequeno principe', 'category_id' => $this->key('cursos')]);
        $this->assertSame([1, $this->key('cursos')], [$this->in(fn () => CategoryRule::count()), $rule->fresh()->category_id]);

        $this->actingAs($this->ana)->post($this->url("/app/regras/{$rule->id}"), ['category_id' => $this->key('salario')])->assertSessionHasErrors('category_id');
        $this->actingAs($this->ana)->post($this->url("/app/regras/{$rule->id}/apagar"));
        $this->assertSame(0, $this->in(fn () => CategoryRule::count()));
    }

    public function test_regras_sao_da_carteira(): void
    {
        $rule = $this->in(fn () => CategoryRule::create(['pattern' => 'segredo', 'kind' => 'gasto', 'category_id' => $this->key('lazer')]));

        $bia = User::factory()->create();
        app(OpenWallet::class)->open($bia, 'Da Bia');
        $this->actingAs($bia)->post($this->url("/app/regras/{$rule->id}/apagar"))->assertNotFound();
        $this->actingAs($bia)->get($this->url('/app/categorias'))->assertDontSee('segredo');

        $viewer = User::factory()->create();
        Membership::create(['organization_id' => $this->wallet->organization_id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $this->actingAs($viewer)->post($this->url('/app/regras'), ['pattern' => 'x', 'category_id' => $this->key('lazer')])->assertForbidden();
        $this->assertNotNull($rule->fresh());
    }
}
