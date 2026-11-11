<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Catalog\Product;
use App\Commerce\Catalog\Variant;
use App\Commerce\Finance\Payable;
use App\Commerce\Inventory\StockLevel;
use App\Commerce\Inventory\StockService;
use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Commerce\Supply\NfeParser;
use App\Commerce\Supply\ReceiptParser;
use App\Commerce\Supply\Supplier;
use App\Commerce\Supply\SupplyBatch;
use App\Commerce\Supply\SupplyService;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Importar remessa de fornecedor (comprovante de consignação em PDF ou texto) e acerto da consignação.
 */
class SupplyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private const ROWS = [
        ['00056', 'CALCINHA CINTURA ALTA MIC AZUL MARINHO | G', '9000026440805', '26,90', '1.0'],
        ['00056', 'CALCINHA CINTURA ALTA MIC AZUL MARINHO | M', '9000026440829', '26,90', '1.0'],
        ['00056', 'CALCINHA CINTURA ALTA MIC BRANCO | M', '9000026440164', '26,90', '2.0'],
        ['00071', 'SUTIA TRIANGULO RENDA | 44', '9000026551112', '59,90', '2.0'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
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

    /** PDF no formato do comprovante da Miza (colunas que saem grudadas no texto do PDF). */
    private function receiptPdf(array $rows = self::ROWS, string $number = '1945'): UploadedFile
    {
        $tr = collect($rows)->map(fn ($r) => "<tr><td>{$r[0]}</td><td style='width:190px'>{$r[1]}</td><td>{$r[2]}</td><td>MIZA</td><td>R$ {$r[3]}</td><td>{$r[4]}</td><td>0.0</td><td>{$r[4]}</td><td>R$ {$r[3]}</td></tr>")->implode('');
        $html = "<h2>Comprovante de consignação</h2><p>Empresa: (B) MIZA LINGERIE MODA INTIMA LTDA (GERENCIAL PARAISO)</p>
            <table><tr><td>Num. venda:</td><td>{$number}</td><td>Data:</td><td>04/11/2026 08:45</td><td>Fechamento: 04/12/2026</td></tr><tr><td>Total:</td><td>R$ 254,40</td></tr></table>
            <table><tr><th>Referência</th><th>Descrição</th><th>Cód. Barras</th><th>Marca</th><th>Preço</th><th>Qtd</th><th>Dev.</th><th>Ent.</th><th>Total</th></tr>{$tr}</table>";
        $pdf = new Dompdf;
        $pdf->loadHtml('<meta charset="utf-8">'.$html, 'UTF-8');
        $pdf->render();
        $path = tempnam(sys_get_temp_dir(), 'consig').'.pdf';
        file_put_contents($path, $pdf->output());

        return new UploadedFile($path, 'comprovante.pdf', 'application/pdf', null, true);
    }

    public function test_le_o_texto_colado_e_agrupa_cor_e_tamanho(): void
    {
        $parsed = ReceiptParser::parse('Empresa: (B) MIZA LINGERIE MODA INTIMA LTDA Num. venda: 77 Data: 01/11/2026 Fechamento: 01/12/2026
            Referência Descrição Cód. Barras Marca Preço Qtd Dev. Ent. Total
            00056 CALCINHA CINTURA ALTA MIC AZUL MARINHO | G 9000026440805 MIZA R$ 26,90 1.0 0.0 1.0 R$ 26,90
            00056 CALCINHA CINTURA ALTA MIC PRETO | M 9000026437997 MIZA R$ 26,90 3.0 1.0 2.0 R$ 53,80
            00080 BODY RENDA | P 9000026999001 MIZA R$ 1.089,90 1.0 1.0 0.0 R$ 0,00');

        $this->assertSame(['Miza Lingerie Moda Intima', '77', '2026-11-01', '2026-12-01'], [$parsed['supplier'], $parsed['number'], $parsed['date'], $parsed['closing']]);
        $this->assertCount(2, $parsed['items']); // o body voltou inteiro (entregue 0)
        $this->assertSame(2, $parsed['items'][1]['quantity']); // vale o "Ent."
        $groups = ReceiptParser::group($parsed['items']);
        $this->assertSame('Calcinha cintura alta mic', $groups[0]['name']);
        $this->assertSame([['Azul Marinho', 'G'], ['Preto', 'M']], array_map(fn ($l) => [$l['color'], $l['size']], $groups[0]['lines']));
        $this->assertSame([7890, 5593], SupplyService::prices(5593, 'custo', 40)); // 55,93 + 40% = 78,30 → 78,90
        $this->assertSame([2690, 1883], SupplyService::prices(2690, 'venda', 30)); // comissão de 30%
    }

    public function test_importa_o_pdf_confere_e_lanca_consignado_e_comprado(): void
    {
        $this->post($this->url('/app/remessas'), ['file' => $this->receiptPdf(), 'basis' => 'venda', 'percent' => 30])->assertSessionHasNoErrors();
        $batch = $this->inStore(fn () => SupplyBatch::sole());
        $this->assertSame(['rascunho', 'Miza Lingerie Moda Intima', '1945', '2026-12-04'], [$batch->status, $batch->supplier, $batch->document_number, $batch->closing_on->toDateString()]);
        $this->assertCount(2, $batch->groups);
        $this->get($this->url("/app/remessas/{$batch->id}"))->assertOk()->assertSee('Lemos <b>6 peças</b>', false)->assertSee('Calcinha cintura alta mic')->assertSee('Azul Marinho');

        $this->post($this->url("/app/remessas/{$batch->id}/lancar"), [
            'supplier' => 'Miza', 'document_number' => '1945', 'closing_on' => '2026-12-04',
            'groups' => [
                ['name' => 'Calcinha Cintura Alta Microfibra', 'mode' => 'consignado', 'sale' => '26,90', 'cost' => '18,83', 'lines' => [['color' => 'Azul Marinho', 'size' => 'G', 'quantity' => 1], ['color' => 'Azul Marinho', 'size' => 'M', 'quantity' => 1], ['color' => 'Branco', 'size' => 'M', 'quantity' => 2]]],
                ['name' => 'Sutiã Triângulo Renda', 'mode' => 'compra', 'sale' => '79,90', 'cost' => '59,90', 'lines' => [['color' => '', 'size' => '44', 'quantity' => 2]]],
            ],
        ])->assertSessionHasNoErrors();

        [$calcinha, $sutia] = $this->inStore(fn () => [Product::where('name', 'Calcinha Cintura Alta Microfibra')->with('variants')->sole(), Product::where('name', 'Sutiã Triângulo Renda')->with('variants')->sole()]);
        $this->assertSame(['Cor', 'Tamanho'], $calcinha->option_names);
        $this->assertSame(['consignado', 'Miza'], [$calcinha->supply_mode, $calcinha->supplier]);
        $branco = $calcinha->variants->first(fn ($v) => $v->options === ['Cor' => 'Branco', 'Tamanho' => 'M']);
        $this->assertSame(['9000026440164', 1883, 2690, 'active'], [$branco->gtin, $branco->cost_cents, $branco->price_cents, $branco->status]);
        $this->assertSame('inactive', $calcinha->variants->first(fn ($v) => $v->options === ['Cor' => 'Branco', 'Tamanho' => 'G'])->status); // não veio na remessa
        $this->assertSame(2, $this->inStore(fn () => StockLevel::where('variant_id', $branco->id)->value('on_hand')));
        $this->assertSame('compra', $sutia->supply_mode);

        // Peças compradas: conta a pagar no fechamento.
        $payable = $this->inStore(fn () => Payable::sole());
        $this->assertSame([11980, '2026-12-04', 'Miza'], [$payable->amount_cents, $payable->due_date->toDateString(), $payable->supplier]);
        $this->get($this->url('/app/produtos'))->assertSee('consignado · Miza');
        $this->assertStringContainsString('9000026440164', $this->get($this->url("/app/remessas/{$batch->id}?exportar=planilha"))->streamedContent());

        // Segunda remessa: reconhece pelo código de barras, soma estoque e cria a cor nova.
        $this->post($this->url('/app/remessas'), ['file' => $this->receiptPdf([['00056', 'CALCINHA CINTURA ALTA MIC BRANCO | M', '9000026440164', '26,90', '3.0'], ['00056', 'CALCINHA CINTURA ALTA MIC PRETO | M', '9000026437997', '26,90', '1.0']], '2001'), 'basis' => 'venda', 'percent' => 30]);
        $second = $this->inStore(fn () => SupplyBatch::where('document_number', '2001')->sole());
        $this->assertSame($calcinha->id, $second->groups[0]['product_id']);
        $this->post($this->url("/app/remessas/{$second->id}/lancar"), ['supplier' => 'Miza', 'groups' => [['name' => 'Calcinha Cintura Alta Microfibra', 'mode' => 'consignado', 'sale' => '26,90', 'cost' => '18,83',
            'lines' => [['color' => 'Branco', 'size' => 'M', 'quantity' => 3], ['color' => 'Preto', 'size' => 'M', 'quantity' => 1]]]]])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->inStore(fn () => Product::where('name', 'like', 'Calcinha%')->count()));
        $this->assertSame(5, $this->inStore(fn () => StockLevel::where('variant_id', $branco->id)->value('on_hand')));
        $this->assertSame('9000026437997', $this->inStore(fn () => Variant::where('product_id', $calcinha->id)->get()->first(fn ($v) => $v->options === ['Cor' => 'Preto', 'Tamanho' => 'M'])->gtin));
    }

    public function test_acerto_devolve_o_que_sobrou_e_cobra_o_vendido(): void
    {
        $this->post($this->url('/app/remessas'), ['file' => $this->receiptPdf(), 'basis' => 'venda', 'percent' => 30]);
        $batch = $this->inStore(fn () => SupplyBatch::sole());
        $this->post($this->url("/app/remessas/{$batch->id}/lancar"), ['supplier' => 'Miza', 'groups' => [
            ['name' => 'Calcinha', 'mode' => 'consignado', 'sale' => '26,90', 'cost' => '20,00', 'lines' => [['quantity' => 1], ['quantity' => 1], ['quantity' => 2]]],
            ['name' => 'Sutiã', 'mode' => 'consignado', 'sale' => '59,90', 'cost' => '40,00', 'lines' => [['quantity' => 2]]],
        ]])->assertSessionHasNoErrors();

        // Vendeu 1 calcinha branca M e 1 sutiã.
        $this->inStore(function () {
            app(StockService::class)->sell(Variant::where('gtin', '9000026440164')->sole(), 1);
            app(StockService::class)->sell(Variant::where('gtin', '9000026551112')->sole(), 1);
        });
        $this->get($this->url("/app/remessas/{$batch->id}"))->assertOk()->assertSee('Acerto da consignação');

        $items = $this->inStore(fn () => $batch->items()->get()->keyBy('barcode'));
        $returns = $items->mapWithKeys(fn ($i) => [$i->id => $i->barcode === '9000026440164' ? 1 : ($i->barcode === '9000026551112' ? 1 : $i->quantity)])->all();
        $this->post($this->url("/app/remessas/{$batch->id}/acerto"), ['returns' => $returns, 'due' => '2026-12-04'])->assertSessionHasNoErrors();

        $this->assertSame('acertada', $batch->fresh()->status);
        $this->assertSame(0, $this->inStore(fn () => StockLevel::where('variant_id', Variant::where('gtin', '9000026440164')->value('id'))->value('on_hand')));
        $payable = $this->inStore(fn () => Payable::sole());
        $this->assertSame(2000 + 4000, $payable->amount_cents); // 1 calcinha + 1 sutiã vendidos, pelo custo
        $this->post($this->url("/app/remessas/{$batch->id}/acerto"), ['returns' => []])->assertSessionHasErrors('status');
    }

    public function test_importacao_respeita_o_limite_de_produtos_do_plano(): void
    {
        config(['commerce.trial_limits.products' => 1]);
        $this->post($this->url('/app/remessas'), ['file' => $this->receiptPdf(), 'basis' => 'venda', 'percent' => 30]);
        $batch = $this->inStore(fn () => SupplyBatch::sole());
        $this->post($this->url("/app/remessas/{$batch->id}/lancar"), ['supplier' => 'Miza', 'groups' => [
            ['name' => 'Calcinha', 'mode' => 'consignado', 'sale' => '26,90', 'cost' => '20,00', 'lines' => [['quantity' => 1], ['quantity' => 1], ['quantity' => 2]]],
            ['name' => 'Sutiã', 'mode' => 'consignado', 'sale' => '59,90', 'cost' => '40,00', 'lines' => [['quantity' => 2]]],
        ]])->assertSessionHasErrors('groups');
        $this->assertSame(0, $this->inStore(fn () => Product::count()));
        $this->assertSame('rascunho', $batch->fresh()->status);
    }

    public function test_comprovante_sem_itens_e_atendente(): void
    {
        $this->post($this->url('/app/remessas'), ['text' => 'texto qualquer', 'basis' => 'venda', 'percent' => 30])->assertSessionHasErrors('file');
        $this->post($this->url('/app/remessas'), ['basis' => 'venda', 'percent' => 30])->assertSessionHasErrors('file');

        $attendant = User::factory()->create();
        Membership::create(['organization_id' => $this->store->organization_id, 'user_id' => $attendant->id, 'role' => 'attendant']);
        $this->actingAs($attendant)->get($this->url('/app/remessas'))->assertForbidden();
    }

    /** Comprovante colado com CNPJ, no formato da Miza. */
    private function receiptText(string $company, string $number, array $rows): string
    {
        return "Comprovante de consignação Empresa: (B) {$company} LTDA CPF/CNPJ: 10.329.195/0001-71 Num. venda: {$number} Data: 04/11/2026 Fechamento: 04/12/2026 Cliente: FULANA DE TAL Documento: 111.222.333-44
            Referência Descrição Cód. Barras Marca Preço Qtd Dev. Ent. Total\n"
            .collect($rows)->map(fn ($r) => "{$r[0]} {$r[1]} {$r[2]} MIZA R$ {$r[3]} {$r[4]} 0.0 {$r[4]} R$ {$r[3]}")->implode("\n");
    }

    public function test_reconhece_a_fornecedora_pelo_cnpj_e_lembra_o_que_foi_corrigido(): void
    {
        $this->post($this->url('/app/remessas'), ['text' => $this->receiptText('MIZA LINGERIE MODA INTIMA', '10', [['00071', 'SUTIA TRIANGULO RENDA NUDE | 44', '9000026551112', '59,90', '2.0']]), 'basis' => 'custo', 'percent' => 50])->assertSessionHasNoErrors();
        $first = $this->inStore(fn () => SupplyBatch::sole());
        $this->assertSame('', $first->groups[0]['lines'][0]['color']); // o leitor não tem como saber que "Nude" é cor
        $this->get($this->url("/app/remessas/{$first->id}"))->assertOk()->assertDontSee('Fornecedora já conhecida');

        // Ela corrige: nome com acento, cor Nude; e chama a fornecedora só de "Miza".
        $this->post($this->url("/app/remessas/{$first->id}/lancar"), ['supplier' => 'Miza', 'groups' => [
            ['name' => 'Sutiã Triângulo Renda', 'mode' => 'compra', 'sale' => '89,90', 'cost' => '59,90', 'lines' => [['color' => 'Nude', 'size' => '44', 'quantity' => 2]]],
        ]])->assertSessionHasNoErrors();
        $supplier = $this->inStore(fn () => Supplier::sole());
        $this->assertSame(['Miza', '10329195000171', 'compra', 'custo', 50.0], [$supplier->name, $supplier->document, $supplier->mode, $supplier->price_basis, $supplier->percent]);

        // Nova remessa, outro nome no cabeçalho mas o mesmo CNPJ; "como da última vez".
        $this->post($this->url('/app/remessas'), ['text' => $this->receiptText('MIZA MODA INTIMA', '11', [
            ['00071', 'SUTIA TRIANGULO RENDA NUDE | 44', '9000026551112', '59,90', '1.0'],
            ['00071', 'SUTIA TRIANGULO RENDA PRETO | 42', '9000026551129', '59,90', '3.0'],
        ]), 'basis' => 'auto'])->assertSessionHasNoErrors();
        $second = $this->inStore(fn () => SupplyBatch::where('document_number', '11')->sole());
        $this->assertSame(['Miza', $supplier->id, 'custo', 50.0], [$second->supplier, $second->supplier_id, $second->price_basis, $second->percent]);
        $this->assertCount(1, $second->groups); // a cor nova vem junto do produto reconhecido
        $group = $second->groups[0];
        $this->assertSame(['Sutiã Triângulo Renda', 'compra'], [$group['product_name'], $group['mode']]);
        $this->assertSame([['Nude', '44', true], ['Preto', '42', false]], array_map(fn ($l) => [$l['color'], $l['size'], ! empty($l['known'])], $group['lines']));
        $this->get($this->url("/app/remessas/{$second->id}"))->assertOk()->assertSee('Fornecedora já conhecida')->assertSee('10.329.195/0001-71')->assertSee('1 de 4 peças', false);
    }

    /** XML de NF-e (com o namespace da SEFAZ), itens sem código de barras. */
    private function nfe(string $number, array $items, array $dups = [], string $cfop = '5102', string $extra = ''): UploadedFile
    {
        $det = collect($items)->map(fn ($i, $n) => '<det nItem="'.($n + 1).'"><prod><cProd>'.$i[0].'</cProd><cEAN>SEM GTIN</cEAN><xProd>'.$i[1].'</xProd><NCM>62121000</NCM><CFOP>'.$cfop.'</CFOP><uCom>UN</uCom><qCom>'.$i[2].'</qCom><vUnCom>'.$i[3].'</vUnCom><vProd>'.number_format($i[2] * $i[3], 2, '.', '').'</vProd><cEANTrib>SEM GTIN</cEANTrib>'.(isset($i[4]) ? '<vDesc>'.$i[4].'</vDesc>' : '').'</prod></det>')->implode('');
        $cobr = $dups ? '<cobr>'.collect($dups)->map(fn ($d, $n) => '<dup><nDup>00'.($n + 1).'</nDup><dVenc>'.$d[0].'</dVenc><vDup>'.$d[1].'</vDup></dup>')->implode('').'</cobr>' : '';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'.$extra.'<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><NFe><infNFe Id="NFe35261100000000000100550010000012341000012345" versao="4.00">'
            .'<ide><nNF>'.$number.'</nNF><dhEmi>2026-11-05T10:00:00-03:00</dhEmi></ide>'
            .'<emit><CNPJ>12345678000190</CNPJ><xNome>CONFECCOES EXEMPLO LTDA</xNome><xFant>RENDAS EXEMPLO</xFant></emit>'
            .$det.'<total><ICMSTot><vNF>100.00</vNF></ICMSTot></total>'.$cobr.'</infNFe></NFe></nfeProc>';
        $path = tempnam(sys_get_temp_dir(), 'nfe').'.xml';
        file_put_contents($path, $xml);

        return new UploadedFile($path, 'nota.xml', 'text/xml', null, true);
    }

    public function test_le_o_xml_da_nota_fiscal_e_reconhece_pelo_codigo_do_fornecedor(): void
    {
        $this->post($this->url('/app/remessas'), ['file' => $this->nfe('1234', [
            ['CR10-PR-M', 'CALCINHA RENDA PRETO M', 2, '15.00', '2.00'],
            ['CR10-PR-G', 'CALCINHA RENDA PRETO G', 1, '15.00'],
            ['CR10-BR-M', 'CALCINHA RENDA BRANCO TAM M', 3, '15.00'],
        ], [['2026-12-05', '30.00'], ['2026-11-20', '60.00']])])->assertSessionHasNoErrors();

        $batch = $this->inStore(fn () => SupplyBatch::sole());
        $this->assertSame(['nfe', 'Rendas Exemplo', '1234', '2026-11-05', '2026-11-20', 'custo', 100.0], [$batch->source, $batch->supplier, $batch->document_number, $batch->received_on->toDateString(), $batch->closing_on->toDateString(), $batch->price_basis, $batch->percent]);
        $this->assertCount(1, $batch->groups);
        $group = $batch->groups[0];
        $this->assertSame(['Calcinha renda', 'compra'], [$group['name'], $group['mode']]);
        $this->assertSame([['Preto', 'M', 1400, 2], ['Preto', 'G', 1500, 1], ['Branco', 'M', 1500, 3]], array_map(fn ($l) => [$l['color'], $l['size'], $l['price_cents'], $l['quantity']], $group['lines']));
        $this->get($this->url("/app/remessas/{$batch->id}"))->assertOk()->assertSee('da nota fiscal')->assertSee('2 parcelas');

        $this->post($this->url("/app/remessas/{$batch->id}/lancar"), ['supplier' => 'Rendas Exemplo', 'document_number' => '1234', 'closing_on' => '2026-11-20', 'groups' => [
            ['name' => 'Calcinha Renda Francesa', 'mode' => 'compra', 'sale' => '39,90', 'cost' => '15,00', 'lines' => [['color' => 'Preto', 'size' => 'M', 'quantity' => 2], ['color' => 'Preto', 'size' => 'G', 'quantity' => 1], ['color' => 'Off White', 'size' => 'M', 'quantity' => 3]]],
        ]])->assertSessionHasNoErrors();

        // Compra de 6 × 15,00 = 90,00 dividida como as duplicatas (60 + 30), na ordem das datas.
        $payables = $this->inStore(fn () => Payable::orderBy('due_date')->get());
        $this->assertSame([['2026-11-20', 6000], ['2026-12-05', 3000]], $payables->map(fn ($p) => [$p->due_date->toDateString(), $p->amount_cents])->all());
        $this->assertStringContainsString('parcela 1/2', $payables[0]->description);

        // Segunda nota: sem código de barras, reconhece pelo código do fornecedor, inclusive o "Off White" corrigido.
        $this->post($this->url('/app/remessas'), ['file' => $this->nfe('1300', [['CR10-BR-M', 'CALCINHA RENDA BRANCO TAM M', 4, '15.00'], ['CR10-PR-G', 'CALCINHA RENDA PRETO G', 1, '15.00']])])->assertSessionHasNoErrors();
        $second = $this->inStore(fn () => SupplyBatch::where('document_number', '1300')->sole());
        $product = $this->inStore(fn () => Product::sole());
        $this->assertSame([$product->id, 'Calcinha Renda Francesa'], [$second->groups[0]['product_id'], $second->groups[0]['product_name']]);
        $this->assertSame([['Off White', 'M', true], ['Preto', 'G', true]], array_map(fn ($l) => [$l['color'], $l['size'], ! empty($l['known'])], $second->groups[0]['lines']));
        $this->assertSame('Rendas Exemplo', $second->supplier);

        // XML com DOCTYPE (entidades) é recusado; XML que não é nota também.
        $this->post($this->url('/app/remessas'), ['file' => $this->nfe('1', [['A', 'X', 1, '1.00']], extra: '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>')])->assertSessionHasErrors('file');
        $this->post($this->url('/app/remessas'), ['text' => '<infNFe><outra/></infNFe>'])->assertSessionHasErrors('file');
        $this->assertSame(2, $this->inStore(fn () => SupplyBatch::count()));
    }

    public function test_nota_de_consignacao_e_tamanho_sem_barra(): void
    {
        $parsed = NfeParser::parse((string) file_get_contents($this->nfe('9', [['B1', 'BODY RENDA TAM: GG', 1, '80.00']], cfop: '5917')->getRealPath()));
        $this->assertTrue($parsed['consigned']);
        $this->assertSame([], $parsed['installments']);
        $this->assertSame(['BODY RENDA', 'GG'], ReceiptParser::splitSize('BODY RENDA TAM: GG'));
        $this->assertSame(['SUTIA BOJO PRETO', '44B'], ReceiptParser::splitSize('SUTIA BOJO PRETO-44B'));
        $this->assertSame(['CALCINHA CINTURA ALTA MIC', ''], ReceiptParser::splitSize('CALCINHA CINTURA ALTA MIC'));
        $this->assertSame(['CAMISOLA LONGA', 'P'], ReceiptParser::splitSize('CAMISOLA LONGA / P'));
    }
}
