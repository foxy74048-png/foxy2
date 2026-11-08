<?php

namespace App\Commerce\Assistant;

use App\Commerce\Access;
use App\Commerce\Commerce;
use App\Models\User;
use App\Platform\ApiClients\ApiClient;
use App\Platform\ApiClients\InternalApi;
use App\Platform\Memberships\Membership;
use App\Platform\Organizations\Organization;
use Foxy\AiClient\FoxyAiClient;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * A raposa no Foxy Commerce: conversa com a Foxy AI só pela API pública (F8), pelo mesmo cliente PHP que os
 * outros sistemas usam, com transporte interno (sem rede). A chave do sistema fica criptografada em disco.
 */
class CommerceFox
{
    private const KEY_FILE = 'commerce-ai-key';

    private const SESSION = 'commerce.fox';

    public function __construct(private CommerceHost $host) {}

    /** Gera a chave do Commerce na Foxy AI e publica perfil, manual e consultas (rodar a cada mudança do manual). */
    public static function publish(): void
    {
        $integration = Commerce::integration();
        ApiClient::where('integration_id', $integration->id)->where('name', 'Foxy Commerce')->whereNull('revoked_at')->get()->each->revoke();
        [, $key] = ApiClient::issue($integration, 'Foxy Commerce', ['ai.host_profiles', 'ai.conversations', 'organizations']);

        $client = new FoxyAiClient(new InternalApi($key));
        foreach ($client->publishProfile(CommerceHost::SLUG, CommerceHost::profile(), CommerceHost::knowledge(), CommerceHost::tools()) as $response) {
            if ($response['status'] >= 300) {
                throw new RuntimeException('Falha ao publicar a raposa do Commerce: '.json_encode($response['body'], JSON_UNESCAPED_UNICODE));
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
        $key = self::key() ?? throw new RuntimeException('Raposa do Commerce não publicada. Rode: php artisan commerce:raposa');

        return (new FoxyAiClient(new InternalApi($key), $organization->getKey()))->withToolLabels(CommerceHost::TOOL_LABELS);
    }

    private static function userRef(User $user): string
    {
        return 'commerce-user-'.$user->getKey();
    }

    /** Raposas disponíveis e a desta pessoa. */
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

    /** O que a janela da raposa precisa para abrir (uma consulta por sessão). */
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

    /** Abre a conversa com as consultas e capacidades que esta pessoa pode usar nesta loja. */
    public function open(Organization $organization, User $user, Membership $membership): array
    {
        $this->ensurePlan($organization);
        $tools = CommerceHost::toolsFor($membership);

        $response = $this->client($organization)->openConversation([
            'host_profile' => CommerceHost::SLUG,
            'user_ref' => self::userRef($user),
            'allowed_tools' => $tools,
            'allowed_capabilities' => array_values(array_filter([
                'suporte', 'erros', $tools !== [] ? 'dados' : null,
                Access::allows($membership, 'commerce.catalog.manage') ? 'escrita' : null,
            ])),
        ]);
        if ($response['status'] >= 300) {
            throw new RuntimeException($response['body']['error']['message'] ?? 'A raposa não está disponível agora.');
        }

        $conversation = ['id' => $response['body']['conversation_id'], 'org' => $organization->getKey(), 'tools' => $tools, 'assistant' => $response['body']['assistant'] ?? null];
        session([self::SESSION => $conversation]);

        return $conversation;
    }

    /** Pergunta → resposta no formato da janela da raposa. */
    public function ask(Organization $organization, User $user, Membership $membership, string $text, ?string $page, ?array $error): array
    {
        $conversation = session(self::SESSION);
        if (! $conversation || $conversation['org'] !== $organization->getKey()) {
            $conversation = $this->open($organization, $user, $membership);
        }

        $run = fn () => $this->client($organization)->ask($conversation['id'], $text,
            fn (string $tool, array $input) => $this->host->run($tool, $input, $conversation['tools']), $page, $error);
        $answer = $run();

        if (in_array($answer['error']['code'] ?? null, ['conversation_full', 'conversation_expired', 'not_found'], true)) {
            $conversation = $this->open($organization, $user, $membership);
            $answer = $run();
        }

        return [
            'reply' => $answer['reply'],
            'drafts' => $answer['drafts'],
            'escalate' => $answer['escalate'],
            'tools_used' => $answer['tools_used'],
            'assistant' => $conversation['assistant'] ?? null,
            'error' => isset($answer['error']) ? ['code' => $answer['error']['code'] ?? 'error', 'message' => $this->friendly($answer['error'])] : null,
        ];
    }

    /** Cada loja do Commerce tem um plano de perguntas na Foxy AI (config commerce.ai_plan). Uma vez por sessão. */
    private function ensurePlan(Organization $organization): void
    {
        $flag = self::SESSION.'.plan.'.$organization->getKey();
        if (! session($flag)) {
            $this->client($organization)->setPlan($organization->getKey(), config('commerce.ai_plan'));
            session([$flag => true]);
        }
    }

    private function friendly(array $error): string
    {
        return match ($error['code'] ?? null) {
            'credit_limit', 'usage_limit' => 'As perguntas da raposa deste mês acabaram. Elas voltam no próximo mês.',
            'ai_unavailable', 'ai_out_of_credit' => 'A raposa está indisponível agora. Tente de novo em alguns minutos.',
            default => $error['message'] ?? 'Não consegui responder agora.',
        };
    }
}
