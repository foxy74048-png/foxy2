<?php

namespace Tests\Feature\Finance;

use App\Finance\Accounts\Account;
use App\Finance\Categories\Category;
use App\Finance\Statements\StatementImport;
use App\Finance\Statements\StatementImporter;
use App\Finance\Statements\StatementLine;
use App\Finance\Statements\StatementParser;
use App\Finance\Transactions\Transaction;
use App\Finance\Transactions\TransactionService;
use App\Finance\Wallets\OpenWallet;
use App\Finance\Wallets\Wallet;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Imports\RawTable;
use App\Platform\Memberships\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsPdf;
use Tests\Concerns\BuildsXlsx;
use Tests\TestCase;

/**
 * Foxy Finanças, fatia 3 (docs/12, seção 5): importação copiada do Foxy Studio. Os primeiros testes são os do
 * Studio (FinancialStatementImportTest, PdfImportTest), com os mesmos arquivos; depois vêm os da revisão no
 * Finanças (lançar, conciliar com o que já estava lançado, dividir, transferência, lote, desfazer).
 */
class StatementImportTest extends TestCase
{
    use BuildsPdf, BuildsXlsx, RefreshDatabase;

    private User $ana;

    private Wallet $wallet;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->ana = User::factory()->create(['email' => 'ana@casa.test']);
        $this->wallet = app(OpenWallet::class)->open($this->ana, 'Pessoal');
        $this->bank = $this->account('PagBank');
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

    private function inWallet(callable $fn, ?Wallet $wallet = null)
    {
        $wallet ??= $this->wallet;

        return app(ContextStore::class)->run(ExecutionContext::forUser($wallet->organization, $this->ana->id), $fn);
    }

    private function account(string $name, string $kind = 'corrente', array $extra = []): Account
    {
        return $this->inWallet(fn () => Account::create(['kind' => $kind, 'name' => $name] + $extra));
    }

    private function upload(string $name, string $content, ?Account $account = null)
    {
        return $this->actingAs($this->ana)->post($this->url('/app/extratos'), [
            'account_id' => ($account ?? $this->bank)->id,
            'file' => UploadedFile::fake()->createWithContent($name, $content),
        ]);
    }

    private function columns(array $data)
    {
        return $this->actingAs($this->ana)->post($this->url('/app/extratos/colunas'), $data);
    }

    private function lines()
    {
        return $this->inWallet(fn () => StatementLine::orderBy('date')->orderBy('created_at')->orderBy('id')->get());
    }

    private function amounts(): array
    {
        return $this->inWallet(fn () => StatementLine::orderBy('created_at')->orderBy('id')->pluck('amount_cents')->all());
    }

    private function line(string $description): StatementLine
    {
        return $this->inWallet(fn () => StatementLine::where('description', $description)->firstOrFail());
    }

    private function category(string $key): Category
    {
        return $this->inWallet(fn () => Category::where('system_key', $key)->firstOrFail());
    }

    /** OFX 1.x (SGML, sem fechar as tags simples), em ISO-8859-1 como muitos bancos mandam. (Studio) */
    private function sgmlOfx(): string
    {
        $body = "OFXHEADER:100\nDATA:OFXSGML\nVERSION:102\nENCODING:USASCII\nCHARSET:1252\n\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS>"
            ."<BANKACCTFROM><BANKID>0290<ACCTID>12345-6</BANKACCTFROM><BANKTRANLIST>\n"
            ."<STMTTRN>\n<TRNTYPE>CREDIT\n<DTPOSTED>20260924120000[-3:BRT]\n<TRNAMT>150.00\n<FITID>A1\n<MEMO>Pix recebido - Carla Souza\n</STMTTRN>\n"
            ."<STMTTRN>\n<TRNTYPE>DEBIT\n<DTPOSTED>20260925\n<TRNAMT>-45.90\n<FITID>A2\n<MEMO>Farmácia São João\n</STMTTRN>\n"
            .'</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>';

        return mb_convert_encoding($body, 'ISO-8859-1', 'UTF-8');
    }

    // ───────────── Leitura (testes do Studio, mesmos arquivos) ─────────────

    public function test_parses_sgml_ofx_in_latin1(): void
    {
        $parsed = (new StatementParser)->parseOfx($this->sgmlOfx());

        $this->assertFalse($parsed['is_credit_card']);
        $this->assertSame('12345-6', $parsed['account_id']);
        $this->assertCount(2, $parsed['rows']);
        $this->assertSame(['date' => '2026-09-24', 'time' => '12:00:00', 'description' => 'Pix recebido - Carla Souza', 'amount' => 150.0, 'external_id' => 'A1'], $parsed['rows'][0]);
        $this->assertNull($parsed['rows'][1]['time'], 'Meia-noite (só a data) fica sem horário.');
        $this->assertSame('Farmácia São João', $parsed['rows'][1]['description']);
        $this->assertSame(-45.9, $parsed['rows'][1]['amount']);
    }

    public function test_parses_xml_credit_card_ofx(): void
    {
        $xml = '<?xml version="1.0"?><OFX><CREDITCARDMSGSRSV1><CCSTMTTRNRS><CCSTMTRS><BANKTRANLIST>'
            .'<STMTTRN><TRNTYPE>DEBIT</TRNTYPE><DTPOSTED>20260920</DTPOSTED><TRNAMT>-89.90</TRNAMT><FITID>X9</FITID><NAME>Loja de Cosméticos</NAME></STMTTRN>'
            .'</BANKTRANLIST></CCSTMTRS></CCSTMTTRNRS></CREDITCARDMSGSRSV1></OFX>';

        $parsed = (new StatementParser)->parseOfx($xml);

        $this->assertTrue($parsed['is_credit_card']);
        $this->assertSame(-89.9, $parsed['rows'][0]['amount']);
        $this->assertSame('Loja de Cosméticos', $parsed['rows'][0]['description']);
    }

    public function test_amount_and_date_formats(): void
    {
        $parser = new StatementParser;

        $this->assertSame(1234.56, $parser->parseAmount('1.234,56'));
        $this->assertSame(-1234.56, $parser->parseAmount('-1,234.56'));
        $this->assertSame(-10.0, $parser->parseAmount('R$ -10,00'));
        $this->assertSame(-10.0, $parser->parseAmount('(10,00)'));
        $this->assertSame(-7.5, $parser->parseAmount('7,50-'));
        foreach (["\u{2212}R$ 45,90", "\u{2013} 45,90", "R$\u{202F}\u{2014}45,90", '-R$ 45,90'] as $raw) {
            $this->assertSame(-45.9, $parser->parseAmount($raw), $raw);
        }
        $this->assertSame('2026-09-24', $parser->parseDate('24/09/2026'));
        $this->assertSame('2026-09-24', $parser->parseDate('2026-09-24'));
        $this->assertSame('2026-09-24', $parser->parseDate('24/09/26 14:32'));
        $this->assertNull($parser->parseDate('Saldo anterior'));
        $this->assertSame('2026-09-04', $parser->parseDate('04 de setembro 2026'));
        $this->assertSame('2026-03-15', $parser->parseDate('15 de março de 2026'));
        $this->assertSame('2026-12-01', $parser->parseDate('1 DEZ 2026'));
        $this->assertNull($parser->parseDate('31 de fevereiro 2026'));
    }

    public function test_pdf_table_is_rebuilt_from_text_positions(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $this->pdf([
            ['Data', 'Histórico', 'Valor', 'Saldo'],
            ['24/09/2026', 'PIX RECEBIDO CARLA SOUZA', '150,00', '1.150,00'],
            ['27/09/2026', 'TED RECEBIDA', '12.300,00', '13.401,60'],
            ['28/09/2026', 'TARIFA', '2,50 D', '13.399,10'],
        ], [40, 110, 420, 520], [2, 3]));

        $table = (new RawTable)->read($path, 'pdf');
        unlink($path);

        $this->assertSame(['Data', 'Histórico', 'Valor', 'Saldo'], $table[0]);
        $this->assertSame(['27/09/2026', 'TED RECEBIDA', '12.300,00', '13.401,60'], $table[2]);
        $this->assertSame('2,50 D', $table[3][2]);
    }

    // ───────────── Importar (fluxos do Studio) ─────────────

    public function test_ofx_upload_creates_lines_to_review_and_reimport_does_not_duplicate(): void
    {
        $this->upload('extrato.ofx', $this->sgmlOfx())->assertRedirect($this->url('/app/extratos'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, '2 linha(s) nova(s)'));
        $this->assertSame([15000, -4590], $this->amounts());

        $this->upload('extrato-de-novo.ofx', $this->sgmlOfx())->assertSessionHas('status', fn ($s) => str_contains($s, '2 já tinham sido importadas'));
        $this->assertCount(2, $this->lines());

        $this->actingAs($this->ana)->get($this->url('/app/extratos'))->assertOk()->assertSee('Farmácia São João')->assertSee('Para revisar (2)');
    }

    public function test_csv_asks_for_columns_once_and_the_account_remembers(): void
    {
        $csv = "Data;Descrição;Valor\n24/09/2026;Venda crédito 2x;1.200,00\n24/09/2026;Taxa;-35,40\n24/09/2026;Taxa;-35,40\nTotal;;1.129,20\n";

        $this->upload('vendas.csv', $csv)->assertRedirect($this->url('/app/extratos/colunas'));
        $this->actingAs($this->ana)->get($this->url('/app/extratos/colunas'))->assertOk()->assertSee('Quais colunas são quais');
        $this->columns(['date' => 0, 'description' => 1, 'amount' => 2, 'has_header' => 1])->assertRedirect($this->url('/app/extratos'));

        // As duas taxas iguais no mesmo dia continuam sendo duas; o "Total" é descartado; o arquivo some.
        $this->assertSame([120000, -3540, -3540], $this->amounts());
        $this->assertEmpty(Storage::disk('local')->allFiles());
        $this->assertSame(['date' => 0, 'description' => 1, 'amount' => 2], array_intersect_key($this->bank->fresh()->statement_mapping, ['date' => 1, 'description' => 1, 'amount' => 1]));

        // Próximo extrato da mesma conta: as colunas já vêm escolhidas.
        $this->upload('outubro.csv', "Dia;Lançamento;Quanto\n01/10/2026;Taxa;-1,00\n");
        $this->actingAs($this->ana)->get($this->url('/app/extratos/colunas'))->assertSee('usou da última vez');
    }

    public function test_expenses_with_other_dashes_or_in_their_own_column_are_not_lost(): void
    {
        $csv = "Data;Histórico;Crédito;Débito\n24/09/2026;Pix recebido Ana;150,00;\n25/09/2026;Conta de luz;;210,35\n25/09/2026;Tarifa;0,00;12,90\n26/09/2026;Pix ???;R$ ??;\n07/10/2026 às 15:09;CNPJ: 22.896.431/0001-10;1 de 13;\n";
        $this->upload('extrato.csv', $csv);
        $this->actingAs($this->ana)->get($this->url('/app/extratos/colunas'))->assertOk()
            ->assertSee('Saída / Débito')->assertSee('<option value="3" selected>', false);

        $this->columns(['date' => 0, 'description' => 1, 'amount' => 2, 'debit' => 3, 'has_header' => 1])
            ->assertRedirect($this->url('/app/extratos'))
            ->assertSessionHasErrors(['file' => '1 linha(s) com data ficaram de fora porque o valor não foi entendido (ex.: “26/09/2026 · Pix ??? · R$ ??”). Confira a coluna do valor ou mande o arquivo para o suporte.']);

        $this->assertSame([15000, -21035, -1290], $this->amounts());
    }

    public function test_card_spreadsheet_with_positive_purchases_is_inverted_by_default(): void
    {
        $card = $this->account('Cartão Nubank', 'cartao', ['closing_day' => 10, 'due_day' => 17]);
        $this->upload('fatura.csv', "date,title,amount\n2026-09-20,Uber,23.50\n2026-09-21,Estorno Uber,-23.50\n", $card);
        $this->actingAs($this->ana)->get($this->url('/app/extratos/colunas'))->assertSee('name="invert_sign" value="1" checked', false);

        $this->columns(['date' => 0, 'description' => 1, 'amount' => 2, 'has_header' => 1, 'invert_sign' => 1]);
        $this->assertSame([-2350, 2350], $this->amounts());
    }

    public function test_excel_statement_with_numeric_dates_and_amounts(): void
    {
        $this->upload('vendas.xlsx', $this->xlsx([['Data', 'Descrição', 'Valor'], [46289, 'Venda débito', 89.9], [46289, 'Taxa', -1.35]]));
        $this->columns(['date' => 0, 'description' => 1, 'amount' => 2, 'has_header' => 1]);

        $this->assertSame(['2026-09-24', '2026-09-24'], $this->lines()->map(fn ($l) => $l->date->toDateString())->all());
        $this->assertSame([8990, -135], $this->amounts());
    }

    public function test_bank_statement_pdf_with_debit_suffix(): void
    {
        $this->upload('extrato.pdf', $this->pdf([
            ['Data', 'Histórico', 'Valor'],
            ['24/09/2026', 'PIX RECEBIDO CARLA', '150,00 C'],
            ['25/09/2026', 'COMPRA FARMACIA', '45,90 D'],
            ['', 'SALDO DO DIA', ''],
        ], [40, 110, 420], [2]))->assertRedirect($this->url('/app/extratos/colunas'));
        $this->columns(['date' => 0, 'description' => 1, 'amount' => 2, 'has_header' => 1]);

        $this->assertSame([15000, -4590], $this->amounts());
    }

    /** Como o PDF do PicPay vem de verdade: nome em 2 linhas e hora/valor na altura do meio. (Studio) */
    public function test_picpay_two_line_names_around_the_amount_row_are_not_mixed_up(): void
    {
        $this->upload('extrato-picpay.pdf', $this->pdf([
            ['04 de setembro 2026', '', '', 'Saldo ao final do dia:', 'R$ 1.031,53'],
            ['Hora', 'Tipo', 'Origem / Destino', 'Forma de pagamento', 'Valor'],
            ['', '', 'Ana Luiza de Moraes', '', ''],
            ['08:58', 'Pix recebido', '', '', '+R$ 30,00'],
            ['', '', 'Correa Noronha', '', ''],
            ['', '', 'CAROLINE DO LAGO', '', ''],
            ['06:30', 'Pix recebido', '', '', '+R$ 30,00'],
            ['', '', 'CARVALHO', '', ''],
            ['03 de setembro 2026', '', '', 'Saldo ao final do dia:', 'R$ 971,53'],
            ['Hora', 'Tipo', 'Origem / Destino', 'Forma de pagamento', 'Valor'],
            ['18:10', 'Pix recebido', 'GUILHERME J C OLIMPIO', '', '+R$ 50,00'],
            ['', '', 'Sandra', '', ''],
            ['09:15', 'Pix recebido', '', '', '+R$ 30,00'],
            ['', '', 'Sandoval', '', ''],
        ], [40, 120, 250, 380, 560], [4]))->assertRedirect($this->url('/app/extratos/colunas'));
        $this->columns(['date' => 0, 'description' => 2, 'amount' => 4, 'has_header' => 0]);

        $this->assertEqualsCanonicalizing([
            ['2026-09-04', 'Ana Luiza de Moraes Correa Noronha'],
            ['2026-09-04', 'CAROLINE DO LAGO CARVALHO'],
            ['2026-09-03', 'GUILHERME J C OLIMPIO'],
            ['2026-09-03', 'Sandra Sandoval'],
        ], $this->lines()->map(fn ($l) => [$l->date->toDateString(), $l->description])->all());
    }

    public function test_time_tells_apart_same_value_pix_and_recognizes_reimports(): void
    {
        $csv = "Data;Descrição;Valor\n04/09/2026 08:58:10;Pix CAROLINE;30,00\n04/09/2026 19:02:45;Pix CAROLINE;30,00\n";
        $import = function (string $content) {
            $this->upload('extrato.csv', $content);
            $this->columns(['date' => 0, 'description' => 1, 'amount' => 2, 'has_header' => 1]);
        };

        $import($csv);
        $this->assertSame(['08:58:10', '19:02:45'], $this->lines()->sortBy('time')->pluck('time')->values()->all());
        // Reimportado com a descrição escrita de outro jeito: nada novo.
        $import(str_replace('Pix CAROLINE', 'PIX RECEBIDO CAROLINE DO LAGO', $csv));
        $this->assertCount(2, $this->lines());
        // Um terceiro Pix, em outro horário, entra.
        $import($csv."04/09/2026 21:00:00;Pix CAROLINE;30,00\n");
        $this->assertCount(3, $this->lines());
        // Duas pessoas diferentes, mesmo valor e mesmo minuto: continuam sendo duas.
        $import("Data;Descrição;Valor\n05/09/2026 10:00:00;Pix JOANA;30,00\n");
        $import("Data;Descrição;Valor\n05/09/2026 10:00:00;Pix PAULA;30,00\n");
        $this->assertCount(5, $this->lines());
    }

    public function test_same_lines_in_another_account_are_not_duplicates(): void
    {
        $other = $this->account('Inter');
        $this->upload('a.ofx', $this->sgmlOfx());
        $this->upload('b.ofx', $this->sgmlOfx(), $other);

        $this->assertCount(4, $this->lines());
    }

    public function test_scanned_pdf_and_unknown_format_get_a_clear_message(): void
    {
        $this->upload('escaneado.pdf', $this->pdf([]))->assertSessionHasErrors(['file' => 'Esse PDF não tem texto para ler — parece ser uma imagem ou digitalização. Baixe o extrato em OFX, Excel ou CSV no app do banco (ou gere o PDF de novo pelo app, não escaneado).']);
        $this->upload('foto.jpg', 'xxx')->assertSessionHasErrors('file');
        $this->assertCount(0, $this->lines());
    }

    // ───────────── Revisar no Finanças ─────────────

    public function test_launch_a_line_with_its_category(): void
    {
        $this->upload('extrato.ofx', $this->sgmlOfx());
        $line = $this->line('Farmácia São João');
        $pharmacy = $this->category('farmacia');

        $this->actingAs($this->ana)->post($this->url("/app/extratos/linhas/{$line->id}/lancar"), ['category_id' => $pharmacy->id])->assertSessionHas('status');

        $t = $this->inWallet(fn () => Transaction::firstOrFail());
        $this->assertSame(['gasto', 4590, '2026-09-25', 'Farmácia São João', $pharmacy->id, 'extrato', $line->id, Transaction::DONE],
            [$t->kind, $t->amount_cents, $t->date->toDateString(), $t->description, $t->category_id, $t->source, $t->statement_line_id, $t->status]);
        $this->assertSame([StatementLine::CREATED, $t->id], [$line->fresh()->status, $line->fresh()->transaction_id]);

        // Clique duplo não lança duas vezes.
        $this->actingAs($this->ana)->post($this->url("/app/extratos/linhas/{$line->id}/lancar"))->assertSessionHasErrors('line');
        $this->assertSame(1, $this->inWallet(fn () => Transaction::count()));
    }

    public function test_what_was_already_launched_by_hand_is_reconciled_not_duplicated(): void
    {
        // Lançado à mão (ou pelo WhatsApp) dois dias antes; e a conta de luz prevista.
        $this->inWallet(function () {
            $svc = app(TransactionService::class);
            $svc->create(['kind' => 'gasto', 'amount_cents' => 4590, 'date' => '2026-09-23', 'description' => 'remédio', 'account_id' => $this->bank->id]);
            $svc->create(['kind' => 'gasto', 'amount_cents' => 21035, 'date' => '2026-12-10', 'description' => 'Luz', 'account_id' => $this->bank->id]);
        });
        $this->upload('extrato.ofx', $this->sgmlOfx());
        $this->upload('luz.csv', "Data;Histórico;Valor\n08/12/2026;CEMIG luz;-210,35\n");
        $this->columns(['date' => 0, 'description' => 1, 'amount' => 2, 'has_header' => 1]);

        $this->actingAs($this->ana)->get($this->url('/app/extratos'))->assertSee('Já está lançado?')->assertSee('“remédio”', false);

        $line = $this->line('Farmácia São João');
        $manual = $this->inWallet(fn () => Transaction::where('description', 'remédio')->firstOrFail());
        $this->actingAs($this->ana)->post($this->url("/app/extratos/linhas/{$line->id}/conciliar"), ['transaction_id' => $manual->id])->assertSessionHas('status');
        $this->assertSame(StatementLine::MATCHED, $line->fresh()->status);
        $this->assertSame($line->id, $manual->fresh()->statement_line_id);
        $this->assertSame(2, $this->inWallet(fn () => Transaction::count()));

        // O previsto vira feito, com a data do extrato.
        $light = $this->line('CEMIG luz');
        $planned = $this->inWallet(fn () => Transaction::where('description', 'Luz')->firstOrFail());
        $this->actingAs($this->ana)->post($this->url("/app/extratos/linhas/{$light->id}/conciliar"), ['transaction_id' => $planned->id]);
        $this->assertSame([Transaction::DONE, '2026-12-08'], [$planned->fresh()->status, $planned->fresh()->date->toDateString()]);

        // Não concilia com lançamento que não confere (valor/sentido) nem já ligado.
        $income = $this->line('Pix recebido - Carla Souza');
        $this->actingAs($this->ana)->post($this->url("/app/extratos/linhas/{$income->id}/conciliar"), ['transaction_id' => $manual->id])->assertSessionHasErrors('line');

        // Desfazer a conciliação só solta: o lançamento continua.
        $this->actingAs($this->ana)->post($this->url("/app/extratos/linhas/{$line->id}/desfazer"));
        $this->assertTrue($line->fresh()->isPending());
        $this->assertNull($manual->fresh()->statement_line_id);
        $this->assertNotNull($manual->fresh());
    }

    public function test_bulk_uses_suggested_category_from_the_last_time_and_skips_what_looks_launched(): void
    {
        $delivery = $this->category('delivery');
        $this->inWallet(fn () => app(TransactionService::class)->create(['kind' => 'gasto', 'amount_cents' => 3990, 'date' => '2026-08-01', 'description' => 'IFOOD *PEDIDO 8812', 'account_id' => $this->bank->id, 'category_id' => $delivery->id]));
        $this->inWallet(fn () => app(TransactionService::class)->create(['kind' => 'gasto', 'amount_cents' => 1000, 'date' => '2026-10-02', 'description' => 'padaria', 'account_id' => $this->bank->id]));

        $this->upload('extrato.csv', "Data;Descrição;Valor\n01/10/2026;IFOOD *PEDIDO 9931;-52,00\n02/10/2026;Padaria Pão Quente;-10,00\n03/10/2026;Loja nova;-80,00\n");
        $this->columns(['date' => 0, 'description' => 1, 'amount' => 2, 'has_header' => 1]);

        $ifood = $this->line('IFOOD *PEDIDO 9931');
        $this->assertSame($delivery->id, $ifood->suggested_category_id);

        $this->actingAs($this->ana)->get($this->url('/app/extratos'))->assertSee('Lançar as 1 que já têm categoria');
        $this->actingAs($this->ana)->post($this->url('/app/extratos/lote'), ['scope' => 'selected', 'line_ids' => $this->lines()->pluck('id')->all()])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '2 linha(s) lançada(s)') && str_contains($s, '1 parecem já estar lançadas'));

        $this->assertSame($delivery->id, $this->inWallet(fn () => Transaction::where('statement_line_id', $ifood->id)->value('category_id')));
        $this->assertTrue($this->line('Padaria Pão Quente')->isPending());
    }

    public function test_old_spreadsheet_with_category_column_and_undo_everything(): void
    {
        $sheet = "Data;O quê;Valor;Categoria\n05/01/2026;Supermercado;-320,00;Mercado\n06/01/2026;Netflix;-55,90;Streaming caro\n10/01/2026;Salário;5000;Salário\n";
        $this->upload('controle-2026.csv', $sheet);
        $this->actingAs($this->ana)->get($this->url('/app/extratos/colunas'))->assertSee('<option value="3" selected>', false);
        $this->columns(['date' => 0, 'description' => 1, 'amount' => 2, 'category' => 3, 'has_header' => 1]);

        $this->assertSame($this->category('mercado')->id, $this->line('Supermercado')->suggested_category_id);
        $this->assertSame($this->category('salario')->id, $this->line('Salário')->suggested_category_id);
        // Categoria que não existia é criada (do tipo certo).
        $new = $this->inWallet(fn () => Category::where('name', 'Streaming caro')->firstOrFail());
        $this->assertSame('gasto', $new->kind);

        $this->actingAs($this->ana)->post($this->url('/app/extratos/lote'), ['scope' => 'with_category'])->assertSessionHas('status', fn ($s) => str_contains($s, '3 linha(s) lançada(s)'));
        $this->assertSame(3, $this->inWallet(fn () => Transaction::count()));

        // Importou com as colunas trocadas? Desfazer tudo apaga também o que foi lançado.
        $import = $this->inWallet(fn () => StatementImport::firstOrFail());
        $this->actingAs($this->ana)->post($this->url("/app/extratos/importacoes/{$import->id}/desfazer"), ['tudo' => 1])->assertSessionHas('status', fn ($s) => str_contains($s, 'já tinham sido lançadas'));
        $this->assertSame(0, $this->inWallet(fn () => Transaction::count()));
        $this->assertCount(0, $this->lines());
        $this->assertNotNull($import->fresh()->undone_at);
    }

    public function test_undo_import_removes_only_lines_to_review_and_allows_resending(): void
    {
        $this->upload('extrato.ofx', $this->sgmlOfx());
        $expense = $this->line('Farmácia São João');
        $this->actingAs($this->ana)->post($this->url("/app/extratos/linhas/{$expense->id}/lancar"));
        $import = $this->inWallet(fn () => StatementImport::firstOrFail());

        $this->actingAs($this->ana)->post($this->url("/app/extratos/importacoes/{$import->id}/desfazer"))->assertSessionHas('status', fn ($s) => str_contains($s, '1 linha(s) apagada(s)'));
        $this->assertSame(StatementLine::CREATED, $expense->fresh()->status);

        $this->upload('extrato.ofx', $this->sgmlOfx());
        $this->assertCount(2, $this->lines());
    }

    public function test_split_must_add_up_and_deleting_a_part_releases_it(): void
    {
        $this->upload('extrato.csv', "Data;Descrição;Valor\n10/11/2026;Supermercado e farmácia;-150,00\n");
        $this->columns(['date' => 0, 'description' => 1, 'amount' => 2, 'has_header' => 1]);
        $line = $this->line('Supermercado e farmácia');

        $this->actingAs($this->ana)->post($this->url("/app/extratos/linhas/{$line->id}/dividir"), ['parts' => [
            ['amount' => '100', 'category_id' => $this->category('mercado')->id], ['amount' => '40', 'category_id' => $this->category('farmacia')->id],
        ]])->assertSessionHasErrors('parts');

        $this->actingAs($this->ana)->post($this->url("/app/extratos/linhas/{$line->id}/dividir"), ['parts' => [
            ['amount' => '110', 'category_id' => $this->category('mercado')->id], ['amount' => '40', 'category_id' => $this->category('farmacia')->id, 'description' => 'Remédios'], ['amount' => ''],
        ]])->assertSessionHasNoErrors();

        $parts = $this->inWallet(fn () => Transaction::orderBy('amount_cents')->get());
        $this->assertSame([4000, 11000], $parts->pluck('amount_cents')->all());
        $this->assertSame('Remédios', $parts[0]->description);
        $this->assertSame(StatementLine::SPLIT, $line->fresh()->status);

        // Apagar uma parte em Lançamentos: a linha perde só ela; apagar a última volta a linha para revisar.
        $this->actingAs($this->ana)->post($this->url("/app/lancamentos/{$parts[0]->id}/apagar"));
        $this->assertSame([$parts[1]->id], $line->fresh()->split_details);
        $this->actingAs($this->ana)->post($this->url("/app/lancamentos/{$parts[1]->id}/apagar"));
        $this->assertTrue($line->fresh()->isPending());
    }

    public function test_card_bill_payment_line_becomes_a_transfer(): void
    {
        $card = $this->account('Cartão Inter', 'cartao', ['closing_day' => 10, 'due_day' => 17]);
        $this->upload('extrato.csv', "Data;Descrição;Valor\n17/11/2026;PAGAMENTO FATURA INTER;-1.250,00\n");
        $this->columns(['date' => 0, 'description' => 1, 'amount' => 2, 'has_header' => 1]);
        $line = $this->line('PAGAMENTO FATURA INTER');

        $this->actingAs($this->ana)->post($this->url("/app/extratos/linhas/{$line->id}/lancar"), ['to_account_id' => $card->id])->assertSessionHas('status', fn ($s) => str_contains($s, 'transferência para Cartão Inter'));

        $t = $this->inWallet(fn () => Transaction::firstOrFail());
        $this->assertSame(['transferencia', $this->bank->id, $card->id, 125000], [$t->kind, $t->account_id, $t->to_account_id, $t->amount_cents]);
    }

    public function test_ignore_clear_and_bring_back(): void
    {
        $this->upload('extrato.ofx', $this->sgmlOfx());
        $line = $this->line('Pix recebido - Carla Souza');
        $this->actingAs($this->ana)->post($this->url("/app/extratos/linhas/{$line->id}/ignorar"));
        $this->assertSame(StatementLine::IGNORED, $line->fresh()->status);

        $this->actingAs($this->ana)->post($this->url('/app/extratos/limpar'), ['scope' => 'ignored'])->assertSessionHas('status');
        $this->assertNotNull($line->fresh()->hidden_at);
        // Escondida continua contando como já importada.
        $this->upload('extrato.ofx', $this->sgmlOfx());
        $this->assertCount(2, $this->lines());

        $this->actingAs($this->ana)->post($this->url("/app/extratos/linhas/{$line->id}/mostrar"));
        $this->assertTrue($line->fresh()->isPending());
        $this->assertNull($line->fresh()->hidden_at);
    }

    public function test_same_pix_written_differently_is_flagged_as_possible_duplicate(): void
    {
        $other = $this->account('Inter');
        $this->upload('a.csv', "Data;Descrição;Valor\n04/09/2026;Pix CAROLINE DO LAGO;30,00\n");
        $this->columns(['date' => 0, 'description' => 1, 'amount' => 2, 'has_header' => 1]);
        $first = $this->line('Pix CAROLINE DO LAGO');
        $this->actingAs($this->ana)->post($this->url("/app/extratos/linhas/{$first->id}/lancar"));

        $this->upload('b.csv', "Data;Descrição;Valor\n04/09/2026;PIX RECEBIDO CAROLINE CARVALHO;30,00\n", $other);
        $this->columns(['date' => 0, 'description' => 1, 'amount' => 2, 'has_header' => 1]);

        $this->actingAs($this->ana)->get($this->url('/app/extratos'))->assertSee('Possível repetido');
    }

    public function test_other_wallet_lines_are_off_limits_and_viewer_cannot_import(): void
    {
        $this->upload('extrato.ofx', $this->sgmlOfx());
        $line = $this->line('Farmácia São João');

        $bia = User::factory()->create();
        app(OpenWallet::class)->open($bia, 'Da Bia');
        $this->actingAs($bia)->post($this->url("/app/extratos/linhas/{$line->id}/lancar"))->assertNotFound();
        $this->actingAs($bia)->get($this->url('/app/extratos'))->assertOk()->assertDontSee('Farmácia São João');
        $import = $this->inWallet(fn () => StatementImport::firstOrFail());
        $this->actingAs($bia)->post($this->url("/app/extratos/importacoes/{$import->id}/desfazer"))->assertNotFound();
        // Nem enviando para uma conta que não é dela.
        $this->actingAs($bia)->post($this->url('/app/extratos'), ['account_id' => $this->bank->id, 'file' => UploadedFile::fake()->createWithContent('x.ofx', $this->sgmlOfx())])->assertSessionHasErrors('account_id');

        $viewer = User::factory()->create();
        Membership::create(['organization_id' => $this->wallet->organization_id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $this->actingAs($viewer)->get($this->url('/app/extratos'))->assertForbidden();
        $this->assertTrue($line->fresh()->isPending());
    }

    public function test_matches_prefer_the_same_account_and_never_offer_one_launch_twice(): void
    {
        $other = $this->account('Inter');
        $this->inWallet(function () use ($other) {
            $svc = app(TransactionService::class);
            $svc->create(['kind' => 'gasto', 'amount_cents' => 3000, 'date' => '2026-09-04', 'description' => 'Uber', 'account_id' => $other->id]);
            $svc->create(['kind' => 'gasto', 'amount_cents' => 3000, 'date' => '2026-09-05', 'description' => 'Uber', 'account_id' => $this->bank->id]);
        });
        $this->upload('a.csv', "Data;Descrição;Valor\n04/09/2026;UBER TRIP;-30,00\n04/09/2026;UBER TRIP 2;-30,00\n04/09/2026;UBER TRIP 3;-30,00\n");
        $this->columns(['date' => 0, 'description' => 1, 'amount' => 2, 'has_header' => 1]);

        $matches = $this->inWallet(fn () => app(StatementImporter::class)->matches($this->lines()));
        $this->assertCount(2, $matches);
        $this->assertSame(2, collect($matches)->pluck('id')->unique()->count());
        $this->assertSame($this->bank->id, $matches[$this->line('UBER TRIP')->id]->account_id);
    }
}
