<?php

namespace Tests\Feature\Admin;

use App\Admin\Metrics\ProductMetrics;
use App\Models\User;
use App\Platform\Products\ProductAccount;
use App\Platform\Products\ProductAccountEvent;
use App\Platform\Products\ProductMetricDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Ai\InteractsWithFoxyAi;
use Tests\TestCase;

/**
 * Métricas do Foxy Admin (docs/08, seção 3.5): histórico de situação/MRR, fechamento do dia, mês a mês
 * (pagantes, churn) e turmas de cadastro (ativação, conversão, quem segue em uso).
 */
class ProductMetricsTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    private string $key;

    private string $integration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeAi();
        [$integration, $this->key] = $this->integrationWithKey('Foxy Studio');
        $this->integration = $integration->id;
    }

    private function snapshot(array $accounts): void
    {
        $this->withToken($this->key)->postJson('/api/v1/product-snapshot', ['metrics' => [], 'accounts' => $accounts])->assertOk();
    }

    private function account(string $ref, string $status, float $mrr, string $signedUp, ?string $activated = null): array
    {
        return ['external_ref' => $ref, 'name' => 'Conta '.$ref, 'status' => $status, 'mrr' => $mrr, 'signed_up_at' => Carbon::parse($signedUp)->toIso8601String(), 'activated_at' => $activated ? Carbon::parse($activated)->toIso8601String() : null];
    }

    private function events(string $ref): array
    {
        $account = ProductAccount::where('external_ref', $ref)->sole();

        return ProductAccountEvent::where('product_account_id', $account->id)->orderBy('occurred_at')->get()
            ->map(fn ($e) => ($e->from_status ?? '—').'→'.$e->to_status)->all();
    }

    public function test_snapshot_records_each_change_of_status_or_mrr_once(): void
    {
        $this->travelTo('2030-01-10 10:00');
        $this->snapshot([$this->account('ws-1', 'trial', 0, '2030-01-02', '2030-01-03'), $this->account('ws-2', 'trial', 0, '2030-01-05')]);
        $this->snapshot([$this->account('ws-1', 'trial', 0, '2030-01-02', '2030-01-03'), $this->account('ws-2', 'trial', 0, '2030-01-05')]);
        $this->assertSame(['—→trial'], $this->events('ws-1'), 'o mesmo retrato de novo não vira mudança');
        $this->assertSame('2030-01-03', ProductAccount::where('external_ref', 'ws-1')->value('activated_at')->toDateString());

        $this->travel(1)->days();
        $this->snapshot([$this->account('ws-1', 'active', 59.9, '2030-01-02', '2030-01-03')]);

        $this->assertSame(['—→trial', 'trial→active'], $this->events('ws-1'));
        $this->assertSame(['—→trial', 'trial→removed'], $this->events('ws-2'), 'quem sumiu do retrato também entra no histórico');
        $this->assertEquals(59.9, ProductAccountEvent::where('to_status', 'active')->value('to_mrr'));
    }

    public function test_daily_closing_is_one_row_per_product_and_day(): void
    {
        $this->travelTo('2030-01-10 10:00');
        $this->snapshot([$this->account('ws-1', 'active', 59.9, '2030-01-02'), $this->account('ws-2', 'trial', 0, '2030-01-05')]);

        $this->artisan('foxy:metricas')->assertSuccessful();
        $this->travel(2)->hours();
        $this->artisan('foxy:metricas')->assertSuccessful();

        $day = ProductMetricDay::sole();
        $this->assertSame(['2030-01-10', 2, 2, 1, 1, 59.9], [substr((string) $day->day, 0, 10), $day->accounts, $day->live, $day->trial, $day->paying, (float) $day->mrr]);
    }

    private function threeMonthsOfHistory(): void
    {
        $this->travelTo('2030-01-10 10:00');
        $this->snapshot([$this->account('ws-a', 'trial', 0, '2030-01-02', '2030-01-03'), $this->account('ws-b', 'trial', 0, '2030-01-05')]);
        $this->artisan('foxy:metricas');

        $this->travelTo('2030-02-03 10:00');
        $this->snapshot([$this->account('ws-a', 'active', 59.9, '2030-01-02', '2030-01-03'), $this->account('ws-b', 'expired', 0, '2030-01-05')]);
        $this->artisan('foxy:metricas');

        $this->travelTo('2030-03-15 10:00');
        $this->snapshot([$this->account('ws-a', 'canceled', 0, '2030-01-02', '2030-01-03'), $this->account('ws-b', 'expired', 0, '2030-01-05'), $this->account('ws-c', 'trial', 0, '2030-03-10', '2030-03-20')]);
    }

    public function test_month_by_month_counts_new_paying_losses_and_churn(): void
    {
        $this->threeMonthsOfHistory();

        $months = collect((new ProductMetrics(null, 6))->monthly())->keyBy(fn ($m) => $m['month']->format('Y-m'));

        $this->assertSame([2, 0, 0], [$months['2030-01']['signups'], $months['2030-01']['new_paying'], $months['2030-01']['lost']]);
        $this->assertSame([1, 1, 59.9], [$months['2030-02']['new_paying'], $months['2030-02']['paying_end'], $months['2030-02']['mrr_end']]);
        $this->assertSame([1, 59.9, 1, 100.0], [$months['2030-03']['lost'], $months['2030-03']['mrr_lost'], $months['2030-03']['paying_start'], $months['2030-03']['churn']]);
        $this->assertSame(0, $months['2030-03']['paying_end'], 'o mês atual usa as contas de agora');
        $this->assertNull($months['2029-12']['churn'], 'antes do histórico não há churn');
    }

    public function test_cohorts_show_activation_conversion_and_who_is_still_using(): void
    {
        $this->threeMonthsOfHistory();

        $cohorts = collect((new ProductMetrics(null, 6))->cohorts())->keyBy(fn ($c) => $c['month']->format('Y-m'));
        $jan = $cohorts['2030-01'];

        $this->assertSame([2, 1, 1, 2, 50.0], [$jan['signups'], $jan['activated_7d'], $jan['paid'], $jan['decided'], $jan['conversion']]);
        $this->assertSame(1.0, $jan['median_days']);
        // Após 1 mês: A em teste (em uso), B vencida. Após 2: A pagando, B vencida. Após 3: ainda não chegou.
        $this->assertSame(['pct' => 50.0, 'live' => 1, 'known' => 2, 'unknown' => 0], $jan['retention'][1]);
        $this->assertSame(50.0, $jan['retention'][2]['pct']);
        $this->assertNull($jan['retention'][3]);

        // Em teste e sem pagar ainda não decidiu: fica fora da conversão. Ativou em 10 dias: não conta nos 7.
        $mar = $cohorts['2030-03'];
        $this->assertSame([1, 0, 0, null], [$mar['signups'], $mar['activated_7d'], $mar['decided'], $mar['conversion']]);

        $summary = (new ProductMetrics(null, 6))->summary();
        $this->assertSame([3, 33.3, 50.0, 100.0], [$summary['signups'], $summary['activation'], $summary['conversion'], $summary['churn']]);
    }

    public function test_account_that_had_left_before_the_history_started_is_not_guessed(): void
    {
        $this->travelTo('2030-01-10 10:00');
        $this->snapshot([$this->account('ws-old', 'canceled', 0, '2029-10-01'), $this->account('ws-live', 'active', 59.9, '2029-10-05')]);
        // Como a migration faz com as contas que já existiam: o 1º registro é o início do histórico.
        ProductAccountEvent::query()->update(['baseline' => true]);

        $oct = collect((new ProductMetrics(null, 6))->cohorts())->first(fn ($c) => $c['month']->format('Y-m') === '2029-10');

        $this->assertSame(['pct' => 100.0, 'live' => 1, 'known' => 1, 'unknown' => 1], $oct['retention'][1]);
        $months = collect((new ProductMetrics(null, 6))->monthly())->keyBy(fn ($m) => $m['month']->format('Y-m'));
        $this->assertSame(0, $months['2030-01']['new_paying'], 'início do histórico não é "virou pagante"');
    }

    public function test_page_is_for_the_foxy_team_filters_by_product_and_exports(): void
    {
        $this->threeMonthsOfHistory();
        [$other] = $this->integrationWithKey('Foxy Commerce');
        ProductAccount::create(['integration_id' => $other->id, 'external_ref' => 'loja-1', 'name' => 'Loja', 'status' => 'active', 'mrr' => 99, 'signed_up_at' => now()]);

        $this->get('/admin/metricas')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->staff()->create());

        $this->get('/admin/metricas')->assertOk()->assertSee('Métricas')->assertSee('Turmas por mês de cadastro')->assertSee('R$ 99,00');
        $this->get('/admin/metricas?produto='.$this->integration.'&meses=6')->assertOk()
            ->assertSee('Mar/2030')->assertDontSee('R$ 99,00')->assertSee('100,0%');
        $this->get('/admin')->assertSee(route('admin.metrics'));
        $this->get('/admin')->assertOk()->assertSee('Crescimento (12 meses)')->assertSee('Conversão de teste em pagante')->assertSee('Churn deste mês');

        $csv = $this->get('/admin/metricas?produto='.$this->integration.'&exportar=planilha')->assertOk()->streamedContent();
        $this->assertStringContainsString('Mês a mês', $csv);
        $this->assertStringContainsString('Turmas por mês de cadastro', $csv);
        $this->assertStringContainsString('Mar/2030', $csv);
        $this->get('/admin/metricas?exportar=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
