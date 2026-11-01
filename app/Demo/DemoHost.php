<?php

namespace App\Demo;

use App\Platform\ApiClients\ApiClient;
use App\Platform\Integrations\Integration;
use App\Platform\Organizations\Organization;
use Foxy\AiClient\FoxyAiClient;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * O "sistema anfitrião" de demonstração: configura a Foxy AI e conversa com ela só pela API,
 * executando as tools sobre os dados de mentira de DemoBusiness.
 */
class DemoHost
{
    private const KEY_FILE = 'demo-api-key';

    public const PLAN = 'ia-50000';

    public function __construct(private DemoBusiness $business = new DemoBusiness) {}

    /**
     * Cria (ou reaproveita) o sistema de demonstração, gera uma chave nova do sistema e, pela API,
     * publica perfil, manual e tools e cria o cliente de demonstração.
     */
    public static function setup(): Organization
    {
        $integration = Integration::firstOrCreate(['name' => 'Foxy — Demonstração']);
        ApiClient::where('integration_id', $integration->id)->where('name', 'Console de testes')->whereNull('revoked_at')
            ->get()->each->revoke();

        [, $key] = ApiClient::issue($integration, 'Console de testes', ['ai.host_profiles', 'ai.conversations', 'organizations']);
        $api = new InternalApi($key);

        foreach ([
            ['PUT', '/host-profiles/'.DemoBusiness::SLUG, DemoBusiness::profile()],
            ['PUT', '/host-profiles/'.DemoBusiness::SLUG.'/knowledge', ['content' => DemoBusiness::knowledge()]],
            ['PUT', '/host-profiles/'.DemoBusiness::SLUG.'/tools', ['tools' => DemoBusiness::tools()]],
            ['POST', '/organizations', ['name' => DemoBusiness::profile()['name'], 'external_ref' => 'demo-clinica']],
        ] as [$method, $uri, $body]) {
            $response = $api->call($method, $uri, $body);

            if ($response['status'] >= 300) {
                throw new RuntimeException("Falha ao configurar a demonstração em {$uri}: ".json_encode($response['body'], JSON_UNESCAPED_UNICODE));
            }
        }

        $organizationId = $response['body']['id'];

        // O console testa todos os recursos: o cliente de demonstração fica no maior plano.
        $plan = $api->call('PUT', "/organizations/{$organizationId}/ai-subscription", ['plan' => self::PLAN]);
        if ($plan['status'] >= 300) {
            throw new RuntimeException('Falha ao definir o plano da demonstração: '.json_encode($plan['body'], JSON_UNESCAPED_UNICODE));
        }

        Storage::put(self::KEY_FILE, Crypt::encryptString(json_encode(['key' => $key, 'organization' => $organizationId])));

        return Organization::findOrFail($organizationId);
    }

    public static function isReady(): bool
    {
        return self::credentials() !== null;
    }

    /**
     * @return array{key: string, organization: string}|null
     */
    private static function credentials(): ?array
    {
        if (! Storage::exists(self::KEY_FILE)) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString(Storage::get(self::KEY_FILE)), true);
        } catch (\Throwable) {
            return null;
        }

        return isset($data['key'], $data['organization']) ? $data : null;
    }

    /**
     * O mesmo cliente PHP que os sistemas Foxy usam (packages/foxy-ai-client), aqui com transporte interno.
     */
    private function client(): FoxyAiClient
    {
        $credentials = self::credentials() ?? throw new RuntimeException('Demonstração não configurada. Rode: php artisan foxy:demo:setup');

        return (new FoxyAiClient(new InternalApi($credentials['key']), $credentials['organization']))
            ->withToolLabels(DemoBusiness::TOOL_LABELS);
    }

    public function chooseFox(string $userRef, string $assistant, ?string $customName): array
    {
        return $this->client()->chooseAssistant($userRef, $assistant, $customName);
    }

    public function assistants(string $userRef): array
    {
        return $this->client()->assistants($userRef);
    }

    public function openConversation(string $userRef, string $role, ?string $model): array
    {
        return $this->client()->openConversation(array_filter([
            'host_profile' => DemoBusiness::SLUG,
            'user_ref' => $userRef,
            'allowed_tools' => DemoBusiness::ROLES[$role],
            'model' => $model,
        ]));
    }

    /**
     * Envia a mensagem e executa as consultas pedidas pela IA sobre os dados de mentira — como faria um sistema real.
     */
    public function ask(string $conversationId, string $text, ?string $page = null, ?array $error = null): array
    {
        return $this->client()->ask($conversationId, $text, fn (string $tool, array $input) => $this->business->run($tool, $input), $page, $error);
    }
}
