<?php

namespace Tests\Feature\Finance;

use App\Ai\Providers\ModelResponse;
use App\Finance\Accounts\Account;
use App\Finance\Transactions\Transaction;
use App\Finance\Wallets\OpenWallet;
use App\Finance\Wallets\Wallet;
use App\Finance\WhatsApp\PhoneLink;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use App\Platform\Messaging\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Ai\InteractsWithFoxyAi;
use Tests\TestCase;

/**
 * Foxy Finanças, fatia 9: lançar pelo WhatsApp — ligar o número por código, a entrada da Evolution (token,
 * repetidas, grupos), "gastei 42,90 no mercado" virando lançamento, desfazer, resumo, carteira do negócio e
 * número que não é de ninguém. Evolution com Http::fake e FakeProvider no lugar do modelo.
 */
class WhatsAppTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    private const TOKEN = 'segredo-do-webhook-123';

    private const PHONE = '5511987654321';

    private User $ana;

    private Wallet $wallet;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 10:00:00');
        Storage::fake();
        $this->setUpFakeAi();
        config(['foxy.messaging.whatsapp' => ['api_url' => 'https://evo.test', 'api_key' => 'k', 'instance' => 'foxy', 'webhook_token' => self::TOKEN]]);
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'x']], 201)]);
        $this->ana = User::factory()->create();
        $this->wallet = app(OpenWallet::class)->open($this->ana, 'Pessoal');
        $this->bank = $this->in(fn () => Account::create(['kind' => 'corrente', 'name' => 'Nubank']), $this->wallet);
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

    private function in(callable $fn, Wallet $wallet)
    {
        return app(ContextStore::class)->run(ExecutionContext::forUser($wallet->organization, $this->ana->id), $fn);
    }

    private function link(?User $user = null, ?Wallet $default = null): PhoneLink
    {
        return PhoneLink::create(['user_id' => ($user ?? $this->ana)->id, 'phone' => self::PHONE, 'verified_at' => now(), 'default_organization_id' => ($default ?? $this->wallet)->organization_id]);
    }

    private function incoming(string $text, ?string $id = null, string $phone = self::PHONE, array $key = [])
    {
        return $this->postJson('/webhooks/whatsapp/'.self::TOKEN, [
            'event' => 'messages.upsert', 'instance' => 'foxy_financas',
            'data' => ['key' => $key + ['remoteJid' => $phone.'@s.whatsapp.net', 'fromMe' => false, 'id' => $id ?? uniqid('M')], 'message' => ['conversation' => $text]],
        ]);
    }

    private function parsed(array $data): ModelResponse
    {
        return new ModelResponse('claude-haiku-5-5', 'end_turn', [['type' => 'text', 'text' => json_encode($data + ['understood' => true, 'account' => null, 'date' => null])]], 300, 40);
    }

    /** @return string[] textos mandados pelo número da Foxy */
    private function sentTexts(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (HttpRequest $r) => str_contains($r->url(), '/message/sendText/'))
            ->map(fn (HttpRequest $r) => $r['text'])->values()->all();
    }

    public function test_liga_o_numero_pelo_codigo_mandado_no_whatsapp(): void
    {
        $this->actingAs($this->ana)->get($this->url('/app/perfil'))->assertOk()->assertSee('Lançar pelo WhatsApp')->assertSee('Mandar código');

        $this->actingAs($this->ana)->post($this->url('/app/perfil/whatsapp'), ['phone' => '(11) 98765-4321'])->assertSessionHas('status');
        preg_match('/\*(\d{6})\*/', $this->sentTexts()[0], $m);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/message/sendText/foxy_financas') && $r['number'] === self::PHONE);

        $this->actingAs($this->ana)->post($this->url('/app/perfil/whatsapp/codigo'), ['code' => $m[1] === '000000' ? '111111' : '000000'])->assertSessionHasErrors('code');
        $this->assertSame(1, PhoneLink::first()->code_attempts);

        // O mesmo número numa conta antiga sai de lá.
        $bia = User::factory()->create();
        PhoneLink::create(['user_id' => $bia->id, 'phone' => self::PHONE, 'verified_at' => now()->subMonth()]);

        $this->actingAs($this->ana)->post($this->url('/app/perfil/whatsapp/codigo'), ['code' => $m[1]])->assertSessionHas('status');
        $link = PhoneLink::where('user_id', $this->ana->id)->first();
        $this->assertTrue($link->isVerified());
        $this->assertSame($this->wallet->organization_id, $link->default_organization_id);
        $this->assertFalse(PhoneLink::where('user_id', $bia->id)->exists());
        $this->assertStringContainsString('gastei 42,90 no mercado', $this->sentTexts()[1]);

        $this->actingAs($this->ana)->get($this->url('/app/perfil'))->assertSee('(11) 98765-4321')->assertSee('Desligar o WhatsApp');
        $this->actingAs($this->ana)->post($this->url('/app/perfil/whatsapp/desligar'));
        $this->assertSame(0, PhoneLink::count());
    }

    public function test_mensagem_vira_lancamento_e_desfazer_apaga(): void
    {
        $this->link();
        $this->ai->push($this->parsed(['kind' => 'gasto', 'amount' => 42.9, 'description' => 'mercado']));

        $this->incoming('gastei 42,90 no mercado', 'M1')->assertOk()->assertJson(['ok' => true]);

        $tx = $this->in(fn () => Transaction::first(), $this->wallet);
        $this->assertSame([4290, 'gasto', 'whatsapp', 'Mercado', $this->bank->id, '2026-11-19', (string) $this->ana->id],
            [$tx->amount_cents, $tx->kind, $tx->source, $tx->description, $tx->account_id, $tx->date->toDateString(), (string) $tx->created_by]);
        $reply = $this->sentTexts()[0];
        $this->assertStringContainsString('R$ 42,90', $reply);
        $this->assertStringContainsString('Nubank', $reply);
        $this->assertStringContainsString('desfazer', $reply);

        // Para a IA vai só o texto, a data e o nome das contas.
        $input = json_encode($this->ai->lastRequest()->messages, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('gastei 42,90 no mercado', $input);
        $this->assertStringContainsString('2026-11-19', $input);

        // A Evolution mandou a mesma mensagem de novo: nada acontece.
        $this->incoming('gastei 42,90 no mercado', 'M1')->assertJson(['duplicate' => true]);
        $this->assertSame(1, count($this->ai->requests));

        $this->incoming('Desfazer', 'M2');
        $this->assertSame(0, $this->in(fn () => Transaction::count(), $this->wallet));
        $this->assertStringContainsString('Apaguei', $this->sentTexts()[1]);
        $this->assertSame(1, count($this->ai->requests));
    }

    public function test_conta_e_data_ditas_na_mensagem_e_frase_sem_valor(): void
    {
        $this->link();
        $card = $this->in(fn () => Account::create(['kind' => 'cartao', 'name' => 'Cartão Inter', 'closing_day' => 5, 'due_day' => 12, 'limit_cents' => 500000]), $this->wallet);
        $this->ai->push($this->parsed(['kind' => 'gasto', 'amount' => 120, 'description' => 'luz', 'account' => 'cartão', 'date' => '2026-11-18']));
        $this->incoming('paguei 120 de luz ontem no cartão');
        $tx = $this->in(fn () => Transaction::first(), $this->wallet);
        $this->assertSame([$card->id, '2026-11-18'], [$tx->account_id, $tx->date->toDateString()]);
        $this->assertStringContainsString('ontem', $this->sentTexts()[0]);

        $this->ai->push($this->parsed(['understood' => false, 'kind' => null, 'amount' => null, 'description' => null]));
        $this->incoming('bom dia!');
        $this->assertStringContainsString('Não entendi o valor', $this->sentTexts()[1]);
        $this->assertSame(1, $this->in(fn () => Transaction::count(), $this->wallet));
    }

    public function test_no_negocio_vai_para_a_carteira_do_negocio_e_quem_so_ve_nao_lanca(): void
    {
        $business = app(OpenWallet::class)->open($this->ana, 'Ateliê', Wallet::BUSINESS, true);
        $caixa = $this->in(fn () => Account::create(['kind' => 'corrente', 'name' => 'Conta PJ']), $business);
        $this->link();
        $this->ai->push($this->parsed(['kind' => 'gasto', 'amount' => 80, 'description' => 'posto']));

        $this->incoming('gastei 80 no posto no negócio');
        $this->assertSame($caixa->id, $this->in(fn () => Transaction::value('account_id'), $business));
        $this->assertSame(0, $this->in(fn () => Transaction::count(), $this->wallet));
        $this->assertStringContainsString('Carteira: Ateliê', $this->sentTexts()[0]);

        $viewer = User::factory()->create();
        Membership::create(['organization_id' => $this->wallet->organization_id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        PhoneLink::create(['user_id' => $viewer->id, 'phone' => '5521999998888', 'verified_at' => now()]);
        $this->incoming('gastei 10 no café', null, '5521999998888');
        $this->assertStringContainsString('só pode ver', $this->sentTexts()[1]);
        $this->assertSame(1, count($this->ai->requests));
    }

    public function test_resumo_e_ajuda_sem_ia(): void
    {
        $this->link();
        $this->in(fn () => app(\App\Finance\Transactions\TransactionService::class)->create(['kind' => 'gasto', 'amount_cents' => 5000, 'date' => '2026-11-10', 'description' => 'Padaria', 'account_id' => $this->bank->id]), $this->wallet);

        $this->incoming('resumo');
        $this->incoming('ajuda');
        [$summary, $help] = $this->sentTexts();
        $this->assertStringContainsString('Saiu: R$ 50,00', $summary);
        $this->assertStringContainsString('desfazer', $help);
        $this->assertSame([], $this->ai->requests);
    }

    public function test_numero_que_nao_e_de_ninguem_recebe_um_convite_por_dia(): void
    {
        $this->incoming('gastei 10', null, '5531988887777');
        $this->incoming('gastei 20', null, '5531988887777');

        $this->assertCount(1, $this->sentTexts());
        $this->assertStringContainsString('crie sua conta', $this->sentTexts()[0]);
        $this->assertSame([], $this->ai->requests);
    }

    public function test_entrada_confere_o_token_e_ignora_grupo_e_o_proprio_numero(): void
    {
        $this->postJson('/webhooks/whatsapp/errado', ['event' => 'messages.upsert'])->assertNotFound();
        $this->incoming('oi', null, self::PHONE, ['fromMe' => true])->assertJson(['ignored' => true]);
        $this->postJson('/webhooks/whatsapp/'.self::TOKEN, ['event' => 'messages.upsert', 'instance' => 'foxy_financas',
            'data' => ['key' => ['remoteJid' => '1203630@g.us', 'fromMe' => false, 'id' => 'G1'], 'message' => ['conversation' => 'oi']]])->assertJson(['ignored' => true]);
        $this->postJson('/webhooks/whatsapp/'.self::TOKEN, ['event' => 'messages.upsert', 'instance' => 'outra-instancia',
            'data' => ['key' => ['remoteJid' => self::PHONE.'@s.whatsapp.net', 'fromMe' => false, 'id' => 'O1'], 'message' => ['conversation' => 'oi']]])->assertJson(['ignored' => true]);

        // Conta nova do WhatsApp (@lid): o número vem ao lado; foto vira um aviso.
        $m = InboundMessage::fromEvolution(['event' => 'MESSAGES_UPSERT', 'instance' => 'i', 'data' => ['key' => ['remoteJid' => '123@lid', 'senderPn' => self::PHONE.'@s.whatsapp.net', 'id' => 'L1'], 'message' => ['imageMessage' => ['caption' => 'cupom']]]]);
        $this->assertSame([self::PHONE, InboundMessage::IMAGE, 'cupom'], [$m->phone, $m->kind, $m->text]);

        $this->link();
        $this->postJson('/webhooks/whatsapp/'.self::TOKEN, ['event' => 'messages.upsert', 'instance' => 'foxy_financas',
            'data' => ['key' => ['remoteJid' => self::PHONE.'@s.whatsapp.net', 'fromMe' => false, 'id' => 'A1'], 'message' => ['audioMessage' => []]]])->assertOk();
        $this->assertStringContainsString('só mensagem escrita', $this->sentTexts()[0]);
    }

    public function test_comando_conectar_cria_a_instancia_e_grava_o_qr_code(): void
    {
        $png = base64_encode('PNG-de-teste');
        // Sem o fake genérico do setUp (o primeiro que combina é o que vale).
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            'evo.test/instance/connectionState/*' => Http::response([], 404),
            'evo.test/instance/create' => Http::response(['qrcode' => ['base64' => 'data:image/png;base64,'.$png]], 201),
            'evo.test/*' => Http::response(['ok' => true], 200),
        ]);
        $path = sys_get_temp_dir().'/financas-qr-teste.png';
        @unlink($path);

        $this->artisan('financas:whatsapp', ['--conectar' => true, '--qr' => $path])->assertSuccessful();

        $this->assertSame('PNG-de-teste', file_get_contents($path));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/instance/create') && $r['instanceName'] === 'foxy_financas');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/webhook/set/foxy_financas'));
        @unlink($path);
    }

    public function test_comando_liga_a_entrada_na_instancia(): void
    {
        $this->artisan('financas:whatsapp')->assertSuccessful();
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/webhook/set/foxy_financas')
            && $r['webhook']['url'] === 'https://'.config('foxy.hosts.financas').'/webhooks/whatsapp/'.self::TOKEN
            && $r['webhook']['events'] === ['MESSAGES_UPSERT']);
    }
}
