<?php

namespace Tests\Feature\Demo;

use App\Ai\HostProfiles\ToolSchema;
use App\Demo\DemoBusiness;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class DemoBusinessTest extends TestCase
{
    private function business(): DemoBusiness
    {
        return new DemoBusiness(CarbonImmutable::parse('2026-10-28 10:00'));
    }

    public function test_catalog_is_valid_and_roles_use_only_catalog_tools(): void
    {
        [$tools, $errors] = ToolSchema::normalizeCatalog(DemoBusiness::tools());

        $this->assertSame([], $errors);
        foreach (DemoBusiness::ROLES as $allowed) {
            $this->assertSame([], array_diff($allowed, array_column($tools, 'name')));
        }
        $this->assertNotContains('financeiro_saldos_em_aberto', DemoBusiness::ROLES['recepcao']);
    }

    public function test_who_owes_the_most_this_month(): void
    {
        $result = $this->business()->run('financeiro_saldos_em_aberto', ['periodo' => '2026-10', 'ordenar' => 'maior_valor', 'limite' => 2]);

        $this->assertSame('Ana Souza', $result[0]['cliente']);
        $this->assertEquals(320.0, $result[0]['em_aberto']);
        $this->assertSame(2, $result[0]['cobrancas']);
        $this->assertCount(2, $result);
    }

    public function test_month_summary(): void
    {
        $summary = $this->business()->run('financeiro_resumo_mes', ['periodo' => '2026-10']);

        $this->assertEquals(650.0, $summary['recebido']);
        $this->assertEquals(760.0, $summary['a_receber']);
        $this->assertSame(9, $summary['atendimentos_realizados']);
    }

    public function test_appointments_tomorrow(): void
    {
        $tomorrow = $this->business()->run('agenda_atendimentos', ['inicio' => '2026-10-29', 'fim' => '2026-10-29']);

        $this->assertSame(['Elisa Prado', 'Diego Rocha'], array_column($tomorrow, 'cliente'));
        $this->assertArrayNotHasKey('valor', $tomorrow[0], 'Agenda não expõe valores.');
    }

    public function test_client_search_returns_no_contact_data(): void
    {
        $found = $this->business()->run('clientes_buscar', ['termo' => 'ana']);

        $this->assertSame('Ana Souza', $found[0]['nome']);
        $this->assertEquals(320.0, $found[0]['saldo_em_aberto']);
        $this->assertSame(['nome', 'ultimo_atendimento', 'proximo_atendimento', 'saldo_em_aberto'], array_keys($found[0]));
    }
}
