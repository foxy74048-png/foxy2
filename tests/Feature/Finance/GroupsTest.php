<?php

namespace Tests\Feature\Finance;

use App\Finance\Debts\Contact;
use App\Finance\Debts\PersonEntry;
use App\Finance\Groups\Group;
use App\Finance\Groups\GroupExpense;
use App\Finance\Groups\GroupLedger;
use App\Finance\Groups\GroupMember;
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
 * Foxy Finanças, fatia 6d: despesas em grupo. Viagem com 4 pessoas — quanto cada um gastou, pagou, quem paga
 * quem (menor número de pagamentos), link para o grupo ver e "Levar para Quem me deve".
 */
class GroupsTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 10:00:00');
        $this->ana = User::factory()->create(['name' => 'Ana']);
        $this->wallet = app(OpenWallet::class)->open($this->ana, 'Pessoal');
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

    private function asAna(string $path, array $data = [])
    {
        return $this->actingAs($this->ana)->call('POST', $this->url($path), $data);
    }

    private function group(): Group
    {
        return $this->in(fn () => Group::firstOrFail());
    }

    /** @return array<string, string> nome => id */
    private function members(): array
    {
        return $this->in(fn () => GroupMember::get()->mapWithKeys(fn ($m) => [$m->is_me ? 'eu' : $m->name => $m->id])->all());
    }

    /**
     * Pousada 1.200 (paguei, os 4 dividem: 300 cada); mercado 300 (Bia pagou, só Você, Bia e Caio: 100 cada);
     * gasolina 200 (Caio pagou, valores: Você 100, Caio 100).
     */
    private function trip(): void
    {
        $this->asAna('/app/grupos', ['name' => 'Viagem Floripa', 'names' => 'Bia, Caio,Duda'])->assertSessionHas('status');
        $g = $this->group();
        $m = $this->members();
        $this->asAna("/app/grupos/{$g->id}/despesas", ['description' => 'Pousada', 'amount' => '1.200', 'date' => '2026-11-10', 'paid_by' => $m['eu'], 'mode' => 'igual', 'participants' => array_values($m)])->assertSessionHasNoErrors();
        $this->asAna("/app/grupos/{$g->id}/despesas", ['description' => 'Mercado', 'amount' => '300', 'date' => '2026-11-11', 'paid_by' => $m['Bia'], 'mode' => 'igual', 'participants' => [$m['eu'], $m['Bia'], $m['Caio']]]);
        $this->asAna("/app/grupos/{$g->id}/despesas", ['description' => 'Gasolina', 'amount' => '200', 'date' => '2026-11-12', 'paid_by' => $m['Caio'], 'mode' => 'valores', 'amounts' => [$m['eu'] => '100', $m['Caio'] => '100']]);
    }

    private function balances()
    {
        return $this->in(fn () => app(GroupLedger::class)->balances($this->group()))->keyBy(fn ($b) => $b['member']->is_me ? 'eu' : $b['member']->name);
    }

    public function test_quanto_cada_um_gastou_pagou_e_quem_paga_quem(): void
    {
        $this->trip();
        $b = $this->balances();

        $this->assertSame([50000, 120000, 70000], [$b['eu']['share'], $b['eu']['paid'], $b['eu']['net']]);
        $this->assertSame([40000, 30000, -10000], [$b['Bia']['share'], $b['Bia']['paid'], $b['Bia']['net']]);
        $this->assertSame([50000, 20000, -30000], [$b['Caio']['share'], $b['Caio']['paid'], $b['Caio']['net']]);
        $this->assertSame([30000, 0, -30000], [$b['Duda']['share'], $b['Duda']['paid'], $b['Duda']['net']]);
        $this->assertSame(0, $b->sum('net'));

        $s = $this->in(fn () => app(GroupLedger::class)->settlements($this->balances()->values()));
        $this->assertCount(3, $s, 'Três pagamentos bastam.');
        $this->assertEqualsCanonicalizing([['Caio', 30000], ['Duda', 30000], ['Bia', 10000]], array_map(fn ($x) => [$x['from']->name, $x['amount']], $s));
        $this->assertTrue(collect($s)->every(fn ($x) => $x['to']->is_me));

        $this->actingAs($this->ana)->get($this->url("/app/grupos/{$this->group()->id}"))->assertOk()
            ->assertSee('Quem paga quem')->assertSee('recebe R$ 700,00')->assertSee('Mandar o resumo no WhatsApp');
        $this->actingAs($this->ana)->get($this->url('/app/grupos'))->assertSee('você recebe R$ 700,00')->assertSee('total R$ 1.700,00');
    }

    public function test_pagamento_entre_eles_acerta_a_conta(): void
    {
        $this->trip();
        $m = $this->members();
        $this->asAna("/app/grupos/{$this->group()->id}/pagamentos", ['from' => $m['Bia'], 'to' => $m['eu'], 'amount' => '100', 'date' => '2026-11-19'])->assertSessionHas('status');

        $b = $this->balances();
        $this->assertSame([0, 60000], [$b['Bia']['net'], $b['eu']['net']]);
        $this->asAna("/app/grupos/{$this->group()->id}/pagamentos", ['from' => $m['Bia'], 'to' => $m['Bia'], 'amount' => '1', 'date' => '2026-11-19'])->assertSessionHasErrors('payment');
    }

    public function test_divisao_igual_nao_perde_centavo_e_valores_tem_que_fechar(): void
    {
        $this->asAna('/app/grupos', ['name' => 'Festa', 'names' => 'Bia, Caio']);
        $g = $this->group();
        $m = $this->members();
        $this->asAna("/app/grupos/{$g->id}/despesas", ['description' => 'Bolo', 'amount' => '100', 'date' => '2026-11-19', 'paid_by' => $m['eu'], 'mode' => 'igual', 'participants' => array_values($m)]);
        $shares = $this->in(fn () => GroupExpense::with('shares')->first()->shares->pluck('amount_cents')->sort()->values()->all());
        $this->assertSame([3333, 3333, 3334], $shares);

        $this->asAna("/app/grupos/{$g->id}/despesas", ['description' => 'X', 'amount' => '100', 'date' => '2026-11-19', 'paid_by' => $m['eu'], 'mode' => 'valores', 'amounts' => [$m['eu'] => '50', $m['Bia'] => '40']])->assertSessionHasErrors('shares');
        $this->asAna("/app/grupos/{$g->id}/despesas", ['description' => 'X', 'amount' => '100', 'date' => '2026-11-19', 'paid_by' => $m['eu'], 'mode' => 'igual', 'participants' => []])->assertSessionHasErrors('shares');
    }

    public function test_levar_para_quem_me_deve_e_encerrar(): void
    {
        $this->trip();
        $this->asAna("/app/grupos/{$this->group()->id}/quem-me-deve")->assertSessionHas('status', fn ($s) => str_contains($s, '3 acerto(s)'));

        $this->assertNotNull($this->group()->closed_at);
        $balances = $this->in(fn () => Contact::withSum('entries', 'amount_cents')->get()->mapWithKeys(fn ($c) => [$c->name => $c->balanceCents()])->all());
        $this->assertSame(['Bia' => 10000, 'Caio' => 30000, 'Duda' => 30000], collect($balances)->sortKeys()->all());
        $this->assertSame('Despesas em grupo', $this->in(fn () => PersonEntry::first()->label()));

        // Encerrado: não lança mais; e não leva duas vezes.
        $m = $this->members();
        $this->asAna("/app/grupos/{$this->group()->id}/despesas", ['description' => 'X', 'amount' => '10', 'date' => '2026-11-19', 'paid_by' => $m['eu'], 'mode' => 'igual', 'participants' => [$m['eu']]])->assertSessionHasErrors('group');
        $this->asAna("/app/grupos/{$this->group()->id}/quem-me-deve")->assertSessionHasErrors('group');
    }

    public function test_link_para_o_grupo_ver_sem_login(): void
    {
        $this->trip();
        $g = $this->group();

        $this->get($this->url("/grupo/{$g->token}"))->assertOk()->assertSee('Viagem Floripa')->assertSee('Duda')
            ->assertSee('Quem criou o grupo')->assertSee('R$ 1.700,00')->assertDontSee('Pessoal')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get($this->url('/grupo/'.str_repeat('a', 40)))->assertNotFound();

        $this->asAna("/app/grupos/{$g->id}/novo-link");
        $this->get($this->url("/grupo/{$g->token}"))->assertNotFound();
        $this->get($this->url("/grupo/{$g->fresh()->token}"))->assertOk();

        // Resumo do WhatsApp com o nome de quem criou.
        $summary = $this->in(fn () => app(GroupLedger::class)->summaryText($this->group(), 'http://x', 'Ana'));
        $this->assertStringContainsString('• Caio → Ana: R$ 300,00', $summary);
    }

    public function test_pessoas_do_grupo_e_acesso(): void
    {
        $this->trip();
        $g = $this->group();
        $caio = $this->in(fn () => GroupMember::where('name', 'Caio')->firstOrFail());
        $this->asAna("/app/grupos/pessoas/{$caio->id}/remover")->assertSessionHasErrors('member');
        $this->asAna("/app/grupos/{$g->id}/pessoas", ['name' => 'bia'])->assertSessionHas('status');
        $this->assertSame(4, $this->in(fn () => GroupMember::count()), 'Mesmo nome não entra duas vezes.');

        $viewer = User::factory()->create();
        Membership::create(['organization_id' => $this->wallet->organization_id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $this->actingAs($viewer)->get($this->url("/app/grupos/{$g->id}"))->assertOk()->assertDontSee('Lançar despesa');
        $this->actingAs($viewer)->post($this->url("/app/grupos/{$g->id}/pagamentos"), [])->assertForbidden();

        $bia = User::factory()->create();
        app(OpenWallet::class)->open($bia, 'Da Bia');
        $this->actingAs($bia)->get($this->url("/app/grupos/{$g->id}"))->assertNotFound();
        $this->actingAs($bia)->post($this->url("/app/grupos/{$g->id}/apagar"))->assertNotFound();
        $this->assertNotNull($this->group());
    }
}
