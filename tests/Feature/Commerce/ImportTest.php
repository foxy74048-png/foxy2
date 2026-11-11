<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Catalog\CatalogService;
use App\Commerce\Catalog\Product;
use App\Commerce\Catalog\ProductImage;
use App\Commerce\Finance\Payable;
use App\Commerce\Finance\Receivable;
use App\Commerce\Imports\PhotoMatcher;
use App\Commerce\Imports\Sheet;
use App\Commerce\Inventory\StockLevel;
use App\Commerce\Orders\Customer;
use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Trazer o que a loja já tem: planilha de produtos e estoque (.xlsx e .csv), planilhas do financeiro e fotos em
 * lote pelo nome do arquivo. Sempre com prévia antes de lançar.
 */
class ImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-11 10:00', 'America/Sao_Paulo'));
        $this->owner = User::factory()->create();
        $this->store = app(OpenStore::class)->open($this->owner, 'Fran Lingerie');
        $this->actingAs($this->owner);
    }

    private function url(string $path): string
    {
        return 'http://'.config('foxy.hosts.commerce').$path;
    }

    private function inStore(callable $callback): mixed
    {
        return app(ContextStore::class)->run(ExecutionContext::forUser($this->store->organization, $this->owner->id), $callback);
    }

    /** CSV como o Excel em português salva: ; e Windows-1252. */
    private function csv(array $rows, string $name = 'estoque.csv', bool $ansi = true): UploadedFile
    {
        $text = implode("\r\n", array_map(fn ($r) => implode(';', $r), $rows));
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $ansi ? mb_convert_encoding($text, 'Windows-1252', 'UTF-8') : $text);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    /** .xlsx mínimo (só a primeira aba), com textos compartilhados, números e data em número de série. */
    private function xlsx(array $rows, string $name = 'planilha.xlsx'): UploadedFile
    {
        $strings = [];
        $xmlRows = '';
        foreach ($rows as $r => $cells) {
            $xmlRows .= '<row r="'.($r + 1).'">';
            foreach (array_values($cells) as $c => $value) {
                $ref = chr(65 + $c).($r + 1);
                if (is_int($value) || is_float($value)) {
                    $xmlRows .= "<c r=\"{$ref}\"><v>{$value}</v></c>";
                } else {
                    $strings[] = htmlspecialchars($value, ENT_XML1);
                    $xmlRows .= "<c r=\"{$ref}\" t=\"s\"><v>".(count($strings) - 1).'</v></c>';
                }
            }
            $xmlRows .= '</row>';
        }
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Estoque" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/aba1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/aba1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xmlRows.'</sheetData></worksheet>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', array_map(fn ($s) => "<si><t>{$s}</t></si>", $strings)).'</sst>');
        $zip->close();

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** Envia, confere a prévia e devolve a URL dela. */
    private function upload(string $type, UploadedFile $file): string
    {
        $response = $this->post($this->url("/app/importar/{$type}"), ['file' => $file])->assertSessionHasNoErrors()->assertRedirect();

        return $response->headers->get('Location');
    }

    public function test_leitura_de_valores_datas_e_titulos(): void
    {
        $this->assertSame(123456, Sheet::cents('R$ 1.234,56'));
        $this->assertSame(8990, Sheet::cents('89,9'));
        $this->assertSame(8990, Sheet::cents('89.90'));
        $this->assertNull(Sheet::cents('abc'));
        $this->assertSame('2026-12-10', Sheet::date('10/12/2026'));
        $this->assertSame('2026-12-10', Sheet::date('10/12/26'));
        $this->assertSame('2026-12-10', Sheet::date('2026-12-10'));
        $this->assertSame('2026-11-11', Sheet::date('46337'));
        $this->assertNull(Sheet::date('31/02/2026'));
        $this->assertSame('preco de venda r', Sheet::key('Preço de venda (R$)'));
        $this->assertFalse(Sheet::int('três'));
    }

    public function test_planilha_de_estoque_em_csv_vira_produtos_com_cor_e_tamanho(): void
    {
        $this->get($this->url('/app/importar'))->assertOk()->assertSee('Produtos e estoque')->assertSee('Contas a receber')->assertSee('Fotos dos produtos em lote');
        $this->get($this->url('/app/importar/modelo/produtos'))->assertOk()->assertDownload('modelo-produtos.csv');

        $preview = $this->upload('produtos', $this->csv([
            ['Estoque da Fran - novembro'],
            ['Produto', 'Categoria', 'Cor', 'Tam', 'Qtd', 'Preço', 'Custo', 'Fornecedor', 'Origem'],
            ['Sutiã Renda Floral', 'Sutiãs', 'PRETO', '42', '3', '89,90', '35,00', 'Miza', 'Consignado'],
            ['Sutiã Renda Floral', 'Sutiãs', 'Preto', '44', '2', '89,90', '35,00', 'Miza', 'Consignado'],
            ['Sutiã Renda Floral', 'Sutiãs', 'Nude', '44', '4', '94,90', '35,00', 'Miza', 'Consignado'],
            ['Sutiã Renda Floral', 'Sutiãs', 'Nude', '44', '1', '', '', '', ''],
            ['Calcinha Fio Dental', 'Calcinhas', 'Vinho', 'P', '6', '29,90', '11', '', 'Comprado'],
            ['', '', '', '', '', '', '', '', ''],
            ['Camisola', 'Pijamas', '', '', 'dois', '139,90', '', '', ''],
            ['Body Novo', '', 'Preto', 'M', '1', '', '', '', ''],
        ]));
        $this->get($preview)->assertOk()->assertSee('Sutiã Renda Floral')->assertSee('Nude · 44 ×5')->assertSee('novo')
            ->assertSee('Linha 9: quantidade "dois" não é um número inteiro')->assertSee('produto novo sem preço de venda')->assertSee('Lançar 2 produto(s)');

        $this->post($preview, ['stock_mode' => 'somar'])->assertRedirect($this->url('/app/produtos'))->assertSessionHas('status');

        $this->inStore(function () {
            $sutia = Product::with('variants')->where('name', 'Sutiã Renda Floral')->first();
            $this->assertSame(['Cor', 'Tamanho'], $sutia->option_names);
            $this->assertSame('Sutiãs', $sutia->category->name);
            $this->assertSame(['Miza', 'consignado'], [$sutia->supplier, $sutia->supply_mode]);
            // Grade 2 cores × 2 tamanhos; "Nude 42" não veio: fica inativa.
            $this->assertCount(4, $sutia->variants);
            $nude42 = $sutia->variants->first(fn ($v) => $v->options === ['Cor' => 'Nude', 'Tamanho' => '42']);
            $this->assertSame('inactive', $nude42->status);
            $nude44 = $sutia->variants->first(fn ($v) => $v->options === ['Cor' => 'Nude', 'Tamanho' => '44']);
            $this->assertSame(9490, $nude44->price_cents);
            $this->assertSame(3500, $nude44->cost_cents);
            $this->assertSame(5, StockLevel::where('variant_id', $nude44->id)->value('on_hand'));
            $this->assertSame(3, StockLevel::where('variant_id', $sutia->variants->first(fn ($v) => $v->options === ['Cor' => 'Preto', 'Tamanho' => '42'])->id)->value('on_hand'));
            $this->assertSame('compra', Product::where('name', 'Calcinha Fio Dental')->value('supply_mode'));
            $this->assertFalse(Product::where('name', 'Body Novo')->exists());
            $this->assertFalse(Product::where('name', 'Camisola')->exists());
        });

        // Prévia usada não serve de novo.
        $this->post($preview, ['stock_mode' => 'somar'])->assertRedirect($this->url('/app/importar'))->assertSessionHasErrors('file');
    }

    public function test_xlsx_com_produto_existente_soma_variacao_nova_ou_iguala_a_contagem(): void
    {
        $this->inStore(fn () => app(CatalogService::class)->create(['name' => 'Calcinha Fio Dental', 'price_cents' => 2990, 'sku' => 'CAL-FIO'], ['Cor', 'Tamanho'], [['Vinho'], ['P', 'M']]));

        $rows = [['Produto', 'Cor', 'Tamanho', 'Quantidade', 'Preço de venda'], ['calcinha fio dental', 'Vinho', 'P', 4, 29.9], ['Calcinha Fio Dental', 'Preta', 'G', 2, 31.9]];
        $preview = $this->upload('produtos', $this->xlsx($rows));
        $this->get($preview)->assertOk()->assertSee('já existe');
        $this->post($preview, ['stock_mode' => 'somar'])->assertSessionHasNoErrors();

        $this->inStore(function () {
            $this->assertSame(1, Product::count());
            $product = Product::with('variants')->first();
            $preta = $product->variants->first(fn ($v) => $v->options === ['Cor' => 'Preta', 'Tamanho' => 'G']);
            $this->assertSame(3190, $preta->price_cents);
            $this->assertSame(2, StockLevel::where('variant_id', $preta->id)->value('on_hand'));
        });

        // Contagem: a planilha diz 1 peça Vinho P → o estoque passa a ser 1 (não soma).
        $preview = $this->upload('produtos', $this->xlsx([['Produto', 'Cor', 'Tamanho', 'Quantidade', 'Preço'], ['Calcinha Fio Dental', 'Vinho', 'P', 1, 29.9]]));
        $this->post($preview, ['stock_mode' => 'igualar'])->assertSessionHasNoErrors();
        $this->inStore(fn () => $this->assertSame(1, StockLevel::where('variant_id', Product::first()->variants->first(fn ($v) => $v->options === ['Cor' => 'Vinho', 'Tamanho' => 'P'])->id)->value('on_hand')));
    }

    public function test_planilha_sem_coluna_obrigatoria_ou_formato_antigo_explica_o_que_fazer(): void
    {
        $this->post($this->url('/app/importar/produtos'), ['file' => $this->csv([['Cor', 'Tamanho', 'Qtd'], ['Preto', 'M', '1']])])
            ->assertSessionHasErrors(['file' => 'Faltou a coluna "Produto" e "Preço de venda" na planilha. Confira os títulos da primeira linha (veja o modelo).']);
        $this->post($this->url('/app/importar/produtos'), ['file' => UploadedFile::fake()->create('velho.xls', 10)])->assertSessionHasErrors('file');
    }

    public function test_fiado_do_caderno_vira_contas_a_receber_com_as_clientes(): void
    {
        $this->inStore(fn () => Customer::create(['name' => 'Bruna M.', 'phone' => '(11) 98111-2222']));

        $preview = $this->upload('receber', $this->csv([
            ['Cliente', 'WhatsApp', 'Descrição', 'Valor', 'Vencimento', 'Parcela', 'Situação'],
            ['Bruna Martins', '11981112222', 'Conjunto renda', '43,30', '10/12/2026', '1/3', 'Aberto'],
            ['Bruna Martins', '11981112222', 'Conjunto renda', '43,30', '10/01/2027', '2/3', ''],
            ['Carla Souza', '11 97333-4444', 'Camisola', '139,90', '05/11/2026', '', 'Pago'],
            ['Dani', '', 'Body', 'R$ 59,90', '20/11/2026', '', ''],
            ['Sem Data', '', 'x', '10', 'amanhã', '', ''],
        ], 'fiado.csv', false));
        $this->get($preview)->assertOk()->assertSee('Bruna Martins')->assertSee('R$ 146,50')->assertSee('Linha 6: vencimento "amanhã" não é uma data');
        $this->post($preview)->assertRedirect($this->url('/app/financeiro?aba=receber'));

        $this->inStore(function () {
            $this->assertSame(4, Receivable::count());
            $bruna = Customer::where('phone', '(11) 98111-2222')->first();
            $this->assertSame(2, $bruna->receivables()->count());
            $this->assertSame('Conjunto renda (1/3)', $bruna->receivables()->orderBy('due_date')->first()->description);
            $this->assertSame(3, Customer::count());
            $carla = Receivable::where('description', 'Camisola')->first();
            $this->assertSame('recebido', $carla->status);
            $this->assertSame('aberto', Receivable::where('description', 'Body')->value('status'));
        });
    }

    public function test_contas_a_pagar_com_categoria(): void
    {
        $preview = $this->upload('pagar', $this->xlsx([
            ['Descrição', 'Fornecedor', 'Categoria', 'Valor', 'Vencimento', 'Situação'],
            ['Acerto outubro', 'Miza', 'Fornecedor', '1.250,00', 46346, ''],
            ['Aluguel', 'Imobiliária', 'aluguel', 800, '05/12/2026', ''],
            ['Sacolas', 'Gráfica', 'Embalagens', 95, '01/11/2026', 'pago'],
            ['Café', '', 'Copa', 20, '15/11/2026', ''],
        ]));
        $this->post($preview)->assertRedirect($this->url('/app/financeiro?aba=pagar'));

        $this->inStore(function () {
            $this->assertSame(4, Payable::count());
            $acerto = Payable::where('description', 'Acerto outubro')->first();
            $this->assertSame([125000, 'fornecedor', 'Miza', '2026-11-20'], [$acerto->amount_cents, $acerto->category, $acerto->supplier, $acerto->due_date->toDateString()]);
            $this->assertSame('aluguel', Payable::where('description', 'Aluguel')->value('category'));
            $this->assertSame('pago', Payable::where('description', 'Sacolas')->value('status'));
            $this->assertSame('outros', Payable::where('description', 'Café')->value('category'));
        });
    }

    public function test_fotos_em_lote_pelo_nome_do_arquivo(): void
    {
        Storage::fake('public');
        $this->inStore(function () {
            app(CatalogService::class)->create(['name' => 'Sutiã Renda Floral', 'price_cents' => 8990, 'sku' => 'SUT-REN'], ['Tamanho', 'Cor'], [['42', '44'], ['Preto', 'Off White']]);
            app(CatalogService::class)->create(['name' => 'Sutiã Renda', 'price_cents' => 7990, 'sku' => 'SUT-R'], ['Cor'], [['Preto']]);
            $matcher = new PhotoMatcher;
            $m = $matcher->match('Sutiã Renda Floral Off White 2.jpg');
            $this->assertSame(['Sutiã Renda Floral', 'Cor', 'Off White'], [$m['product']->name, $m['option_name'], $m['option_value']]);
            $this->assertSame([null, 'Sutiã Renda Floral'], [$matcher->match('sutia-renda-floral.jpg')['option_value'], $matcher->match('sutia-renda-floral.jpg')['product']->name]);
            $this->assertSame('Sutiã Renda', $matcher->match('SUTIA RENDA PRETO (3).png')['product']->name);
            $this->assertNull($matcher->match('IMG_2031.jpg'));
        });
        $this->get($this->url('/app/importar/fotos'))->assertOk()->assertSee('Fotos em lote');

        $this->postJson($this->url('/app/importar/fotos'), ['image' => UploadedFile::fake()->image('Sutiã Renda Floral Preto.jpg')])
            ->assertOk()->assertJson(['ok' => true, 'product' => 'Sutiã Renda Floral', 'option' => 'Cor: Preto']);
        $this->postJson($this->url('/app/importar/fotos'), ['image' => UploadedFile::fake()->image('IMG_2031.jpg')])->assertStatus(422)->assertJson(['ok' => false]);
        $this->inStore(fn () => $this->assertSame(['Cor', 'Preto'], [ProductImage::first()->option_name, ProductImage::first()->option_value]));
    }

    public function test_quem_nao_pode_nao_importa_e_previa_e_da_propria_loja(): void
    {
        $attendant = User::factory()->create();
        Membership::create(['organization_id' => $this->store->organization_id, 'user_id' => $attendant->id, 'role' => 'attendant']);
        $preview = $this->upload('pagar', $this->csv([['Descrição', 'Valor', 'Vencimento'], ['Luz', '100', '10/12/2026']]));

        $this->actingAs($attendant);
        $this->get($this->url('/app/importar'))->assertForbidden();
        $this->post($this->url('/app/importar/receber'), ['file' => $this->csv([['Cliente', 'Valor', 'Vencimento']])])->assertForbidden();
        $this->post($this->url('/app/importar/fotos'))->assertForbidden();

        // Outra pessoa com acesso ao financeiro não usa a prévia de quem enviou.
        $manager = User::factory()->create();
        Membership::create(['organization_id' => $this->store->organization_id, 'user_id' => $manager->id, 'role' => 'manager']);
        $this->actingAs($manager)->post($preview)->assertSessionHasErrors('file');
        $this->inStore(fn () => $this->assertSame(0, Payable::count()));
    }
}
