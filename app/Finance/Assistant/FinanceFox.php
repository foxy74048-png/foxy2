<?php

namespace App\Finance\Assistant;

use App\Finance\Categories\Category;
use App\Finance\Finance;
use App\Finance\Statements\StatementLine;
use App\Finance\Wallets\Wallet;
use App\Models\User;
use App\Platform\ApiClients\ApiClient;
use App\Platform\ApiClients\InternalApi;
use App\Platform\Memberships\Membership;
use App\Platform\Organizations\Organization;
use Foxy\AiClient\FoxyAiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * A raposa no Foxy Finanças (docs/12 §14): conversa e tarefas avulsas pela API pública da Foxy AI, com o mesmo
 * cliente PHP dos outros sistemas e transporte interno (sem rede). Igual ao CommerceFox, mais as tarefas.
 */
class FinanceFox
{
    private const KEY_FILE = 'finance-ai-key';

    private const SESSION = 'finance.fox';

    public function __construct(private FinanceHost $host) {}

    /** Gera a chave do Finanças na Foxy AI e publica perfil, manual e consultas (rodar a cada mudança do manual). */
    public static function publish(): void
    {
        $integration = Finance::integration();
        ApiClient::where('integration_id', $integration->id)->where('name', Finance::NAME)->whereNull('revoked_at')->get()->each->revoke();
        [, $key] = ApiClient::issue($integration, Finance::NAME, ['ai.host_profiles', 'ai.conversations', 'ai.tasks', 'organizations']);

        $client = new FoxyAiClient(new InternalApi($key));
        foreach ($client->publishProfile(FinanceHost::SLUG, FinanceHost::profile(), FinanceHost::knowledge(), FinanceHost::tools()) as $response) {
            if ($response['status'] >= 300) {
                throw new RuntimeException('Falha ao publicar a raposa do Finanças: '.json_encode($response['body'], JSON_UNESCAPED_UNICODE));
            }
        }

        Storage::put(self::KEY_FILE, Crypt::encryptString($key));
    }

    public static function isReady(): bool
    {
        return self::key() !== null;
    }

    private static function key(): ?string
    {
        try {
            return Storage::exists(self::KEY_FILE) ? Crypt::decryptString(Storage::get(self::KEY_FILE)) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function client(Organization $organization): FoxyAiClient
    {
        $key = self::key() ?? throw new RuntimeException('Raposa do Finanças não publicada. Rode: php artisan financas:raposa');

        return (new FoxyAiClient(new InternalApi($key), $organization->getKey()))->withToolLabels(FinanceHost::TOOL_LABELS);
    }

    private static function userRef(User $user): string
    {
        return 'financas-user-'.$user->getKey();
    }

    public function assistants(Organization $organization, User $user): array
    {
        $response = $this->client($organization)->assistants(self::userRef($user));

        return $response['status'] < 300 ? $response['body'] : ['data' => [], 'visuals' => [], 'current' => null];
    }

    public function choose(Organization $organization, User $user, string $fox, ?string $visual, ?string $name): array
    {
        $response = $this->client($organization)->chooseAssistant(self::userRef($user), $fox, $name, $visual);
        session()->forget(self::SESSION);
        session()->forget(self::SESSION.'.widget.'.$organization->getKey());

        return $response['body']['current'] ?? [];
    }

    public function widget(Organization $organization, User $user): array
    {
        $key = self::SESSION.'.widget.'.$organization->getKey();
        if (! session($key)) {
            $list = $this->assistants($organization, $user);
            session([$key => [
                'assistant' => $list['current'] ?? null,
                'setup' => ['foxes' => $list['data'] ?? [], 'visuals' => $list['visuals'] ?? [], 'required' => ($list['current']['source'] ?? 'foxy') === 'foxy'],
            ]]);
        }

        return session($key);
    }

    public function forget(): void
    {
        session()->forget(self::SESSION);
    }

    public function open(Organization $organization, User $user, Membership $membership, Wallet $wallet): array
    {
        $this->ensurePlan($organization);
        $tools = FinanceHost::toolsFor($membership, $wallet);

        $response = $this->client($organization)->openConversation([
            'host_profile' => FinanceHost::SLUG,
            'user_ref' => self::userRef($user),
            'allowed_tools' => $tools,
            'allowed_capabilities' => array_values(array_filter(['suporte', 'erros', $tools !== [] ? 'dados' : null])),
        ]);
        if ($response['status'] >= 300) {
            throw new RuntimeException($response['body']['error']['message'] ?? 'A raposa não está disponível agora.');
        }

        $conversation = ['id' => $response['body']['conversation_id'], 'org' => $organization->getKey(), 'tools' => $tools, 'assistant' => $response['body']['assistant'] ?? null];
        session([self::SESSION => $conversation]);

        return $conversation;
    }

    public function ask(Organization $organization, User $user, Membership $membership, Wallet $wallet, string $text, ?string $page, ?array $error): array
    {
        $conversation = session(self::SESSION);
        if (! $conversation || $conversation['org'] !== $organization->getKey()) {
            $conversation = $this->open($organization, $user, $membership, $wallet);
        }

        $run = fn () => $this->client($organization)->ask($conversation['id'], $text,
            fn (string $tool, array $input) => $this->host->run($tool, $input, $conversation['tools']), $page, $error);
        $answer = $run();

        if (in_array($answer['error']['code'] ?? null, ['conversation_full', 'conversation_expired', 'not_found'], true)) {
            $conversation = $this->open($organization, $user, $membership, $wallet);
            $answer = $run();
        }

        return [
            'reply' => $answer['reply'], 'drafts' => $answer['drafts'], 'escalate' => $answer['escalate'], 'tools_used' => $answer['tools_used'],
            'assistant' => $conversation['assistant'] ?? null,
            'error' => isset($answer['error']) ? ['code' => $answer['error']['code'] ?? 'error', 'message' => self::friendly($answer['error'])] : null,
        ];
    }

    /**
     * Sugere categoria para até 50 linhas do extrato que ficaram sem (tarefa finance.categorize). Só a descrição e
     * o tipo vão para a IA — nada de valor, data ou conta. Devolve [id da linha => categoria] (só as que ela soube).
     *
     * @param  Collection<int, StatementLine>  $lines
     * @return array<string, Category>
     */
    public function categorize(Organization $organization, Collection $lines): array
    {
        $lines = $lines->take(50)->values();
        $categories = Category::query()->whereNull('hidden_at')->with('parent')->get();
        $this->ensurePlan($organization);

        $response = $this->client($organization)->runTask('finance.categorize', [
            'items' => $lines->map(fn (StatementLine $l, int $i) => ['index' => $i, 'description' => self::privacy($l->description), 'kind' => $l->kind()])->all(),
            'categories' => $categories->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->fullName(), 'kind' => $c->kind])->values()->all(),
        ]);
        $this->guard($response);

        $byId = $categories->keyBy('id');
        $out = [];
        foreach ($response['body']['output']['items'] ?? [] as $item) {
            $line = $lines[$item['index'] ?? -1] ?? null;
            $category = $byId->get($item['category_id'] ?? '');
            // Só aceita categoria que existe na carteira e é do mesmo tipo da linha.
            if ($line && $category && $category->kind === $line->kind()) {
                $out[$line->id] = $category;
            }
        }

        return $out;
    }

    /**
     * Dicas do mês (tarefa finance.tips): os fatos já calculados viram 3 frases e 1 sugestão. Frase com valor que
     * não está nos fatos é descartada (a IA não inventa número).
     *
     * @param  string[]  $facts
     * @return array{sentences: string[], suggestion: ?string}
     */
    public function tips(Organization $organization, string $monthLabel, array $facts): array
    {
        $this->ensurePlan($organization);
        $response = $this->client($organization)->runTask('finance.tips', ['month' => $monthLabel, 'facts' => array_values($facts)]);
        $this->guard($response);

        $allowed = implode(' ', $facts);
        $keep = fn (?string $s) => $s !== null && trim($s) !== '' && collect(self::moneyIn($s))->every(fn ($v) => str_contains($allowed, $v));
        $output = $response['body']['output'] ?? [];

        return [
            'sentences' => array_values(array_filter(array_map('trim', (array) ($output['sentences'] ?? [])), $keep)),
            'suggestion' => $keep($output['suggestion'] ?? null) ? trim($output['suggestion']) : null,
        ];
    }

    /** @return string[] valores "R$ 1.234,56" citados num texto */
    public static function moneyIn(string $text): array
    {
        preg_match_all('/R\$\s?-?\d{1,3}(?:\.\d{3})*(?:,\d{2})?/u', $text, $m);

        return array_map(fn ($v) => preg_replace('/R\$\s?/u', 'R$ ', $v), $m[0]);
    }

    /** CPF/CNPJ e números longos (conta, documento) saem da descrição antes de ir para a IA. */
    private static function privacy(string $description): string
    {
        return trim(preg_replace(['/\d{3}\.?\d{3}\.?\d{3}-?\d{2}/', '/\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}/', '/\d{6,}/'], ' ', $description) ?? $description);
    }

    /** Erro da Foxy AI vira exceção com a mensagem para a pessoa. */
    private function guard(array $response): void
    {
        if ($response['status'] >= 300) {
            throw new RuntimeException(self::friendly($response['body']['error'] ?? []));
        }
    }

    /** Cada carteira tem um plano de créditos na Foxy AI (config finance.ai_plan). Uma vez por sessão. */
    private function ensurePlan(Organization $organization): void
    {
        $flag = self::SESSION.'.plan.'.$organization->getKey();
        if (! session($flag)) {
            $this->client($organization)->setPlan($organization->getKey(), config('finance.ai_plan'));
            session([$flag => true]);
        }
    }

    public static function friendly(array $error): string
    {
        return match ($error['code'] ?? null) {
            'credit_limit', 'usage_limit' => 'Os créditos da raposa deste mês acabaram. Eles voltam no próximo mês.',
            'ai_unavailable', 'ai_out_of_credit', 'task_failed' => 'A raposa está indisponível agora. Tente de novo em alguns minutos.',
            'rate_limited' => 'Muitos pedidos seguidos. Espere um minuto e tente de novo.',
            default => $error['message'] ?? 'A raposa não conseguiu agora.',
        };
    }
}
