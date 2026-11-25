<?php

namespace Tests\Feature\Finance;

use App\Finance\Accounts\Account;
use App\Finance\Transactions\TransactionService;
use App\Finance\Wallets\OpenWallet;
use App\Finance\Wallets\Wallet;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Calendário do mês: o que sai e entra em cada dia e o vencimento das faturas (as compras no cartão ficam na fatura).
 */
class CalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-12 10:00:00');
        $this->ana = User::factory()->create();
        $this->wallet = app(OpenWallet::class)->open($this->ana, 'Pessoal');
        $this->in(function () {
            $bank = Account::create(['kind' => 'corrente', 'name' => 'Nubank']);
            $card = Account::create(['kind' => 'cartao', 'name' => 'Cartão Inter', 'closing_day' => 10, 'due_day' => 17]);
            $svc = app(TransactionService::class);
            $svc->create(['kind' => 'recebimento', 'amount_cents' => 500000, 'date' => '2026-11-05', 'description' => 'Salário', 'account_id' => $bank->id]);
            $svc->create(['kind' => 'gasto', 'amount_cents' => 120000, 'date' => '2026-11-20', 'description' => 'Aluguel', 'account_id' => $bank->id, 'status' => 'previsto']);
            $svc->create(['kind' => 'gasto', 'amount_cents' => 8990, 'date' => '2026-11-03', 'description' => 'Farmácia no cartão', 'account_id' => $card->id]);
            $svc->create(['kind' => 'gasto', 'amount_cents' => 4000, 'date' => '2026-12-02', 'description' => 'Padaria de dezembro', 'account_id' => $bank->id]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function in(callable $fn)
    {
        return app(ContextStore::class)->run(ExecutionContext::forUser($this->wallet->organization, $this->ana->id), $fn);
    }

    private function page(string $query = '')
    {
        return $this->actingAs($this->ana)->withSession(['finance.organization_id' => $this->wallet->organization_id])
            ->get('http://'.config('foxy.hosts.financas').'/app/calendario'.$query);
    }

    public function test_mes_com_contas_recebimentos_e_a_fatura_no_dia_do_vencimento(): void
    {
        $page = $this->page()->assertOk()
            ->assertSee('Novembro de 2026')
            ->assertSee('Salário')
            ->assertSee('Aluguel')
            ->assertSee('Fatura Cartão Inter')
            ->assertSee('17/11')
            ->assertDontSee('Padaria de dezembro');

        // A compra do cartão não aparece solta no dia 3: está dentro da fatura.
        $page->assertDontSee('Farmácia no cartão');
        // Falta pagar = aluguel previsto + a fatura (sem pagamento).
        $page->assertSee('1.289,90');

        $this->page('?mes=2026-12')->assertOk()->assertSee('Padaria de dezembro')->assertDontSee('Aluguel');
    }

    public function test_resumo_semanal_pelo_whatsapp_so_para_quem_ligou(): void
    {
        Carbon::setTestNow('2026-11-15 09:00:00'); // domingo
        config(['foxy.messaging.whatsapp' => ['api_url' => 'https://evo.test', 'api_key' => 'k', 'instance' => 'foxy', 'webhook_token' => 'segredo']]);
        \Illuminate\Support\Facades\Http::fake(['evo.test/*' => \Illuminate\Support\Facades\Http::response(['key' => ['id' => 'x']], 201)]);
        $link = \App\Finance\WhatsApp\PhoneLink::create(['user_id' => $this->ana->id, 'phone' => '5511987654321', 'verified_at' => now()]);
        $this->in(fn () => app(TransactionService::class)->create(['kind' => 'gasto', 'amount_cents' => 15000, 'date' => '2026-11-10', 'description' => 'Mercado', 'account_id' => Account::where('name', 'Nubank')->value('id')]));

        $this->artisan('financas:resumo-semanal')->assertSuccessful();
        \Illuminate\Support\Facades\Http::assertNothingSent();

        $url = 'http://'.config('foxy.hosts.financas').'/app/perfil';
        $this->actingAs($this->ana)->get($url)->assertSee('Ligar resumo');
        $this->actingAs($this->ana)->post($url.'/whatsapp/resumo-semanal', ['on' => '1'])->assertSessionHas('status');
        $this->assertTrue($link->fresh()->weekly_summary);

        $this->artisan('financas:resumo-semanal')->assertSuccessful();
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendText/') && $r['number'] === '5511987654321'
            && str_contains($r['text'], 'Sua semana em Pessoal') && str_contains($r['text'], '08/11 a 14/11')
            && str_contains($r['text'], 'Saiu: R$ 150,00') && str_contains($r['text'], '17/11 Fatura Cartão Inter R$ 89,90')
            && str_contains($r['text'], '20/11 Aluguel R$ 1.200,00'));
    }

    public function test_calendario_exporta(): void
    {
        $csv = $this->page('?exportar=planilha')->assertOk()->streamedContent();
        $this->assertStringContainsString('Aluguel', $csv);
        $this->assertStringContainsString('Fatura Cartão Inter', $csv);
        $this->page('?exportar=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
