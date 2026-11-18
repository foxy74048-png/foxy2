<?php

namespace Tests\Feature\Platform;

use App\Platform\Exports\Csv;
use App\Platform\Exports\Export;
use App\Platform\Imports\Sheet;
use App\Platform\Support\Money;
use Tests\TestCase;

/**
 * Fatia 0 do Foxy Finanças (docs/12): leitura de planilha, exportação e dinheiro saíram do Commerce para a
 * Platform, para todos os produtos usarem. O Commerce continua com os mesmos nomes (estende a Platform).
 */
class SharedImportExportTest extends TestCase
{
    public function test_commerce_continua_usando_as_pecas_da_platform(): void
    {
        $this->assertTrue(is_subclass_of(\App\Commerce\Imports\Sheet::class, Sheet::class));
        $this->assertTrue(is_subclass_of(\App\Commerce\Insights\Export::class, Export::class));
        $this->assertTrue(is_subclass_of(\App\Commerce\Insights\Csv::class, Csv::class));
        $this->assertTrue(is_subclass_of(\App\Commerce\Catalog\Money::class, Money::class));
    }

    public function test_dinheiro_e_planilha_no_jeito_brasileiro(): void
    {
        $this->assertSame(123456, Money::toCents('1.234,56'));
        $this->assertSame('R$ 1.234,56', Money::format(123456));
        $this->assertSame("'=SOMA(A1)", Csv::cell('=SOMA(A1)'));
        $this->assertSame('-12,50', Csv::cell('-12,50'));
    }

    public function test_csv_de_extrato_e_lido_pela_platform(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "Data;Descrição;Valor\n05/11/2026;IFOOD *Pedido;-42,90\n");

        $rows = Sheet::read($path, 'extrato.csv');
        unlink($path);

        $row = array_values($rows)[0];
        $this->assertSame('IFOOD *Pedido', Sheet::pick($row, ['descricao']));
        $this->assertSame(-4290, Sheet::cents(Sheet::pick($row, ['valor'])));
    }

    public function test_pdf_leva_o_nome_do_produto_que_exportou(): void
    {
        $html = view('platform.export-pdf', [
            'title' => 'Lançamentos', 'product' => 'Foxy Finanças', 'store' => 'Carteira da Ana', 'filters' => [],
            'sections' => [], 'truncated' => false, 'generatedAt' => now(),
        ])->render();

        $this->assertStringContainsString('Foxy Finanças · Carteira da Ana', $html);
        $this->assertStringNotContainsString('Foxy Commerce', $html);
    }
}
