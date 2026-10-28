<?php

namespace App\Demo;

use App\Platform\ApiClients\ApiClient;
use App\Platform\Organizations\Organization;
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

    private const MAX_STEPS = 6;

    public function __construct(private DemoBusiness $business = new DemoBusiness) {}

    /**
     * Cria (ou reaproveita) a organização de demonstração, gera uma chave nova e publica perfil, manual e tools.
     */
    public static function setup(): Organization
    {
        $organization = Organization::firstOrCreate(['name' => 'Foxy — Demonstração']);
        ApiClient::where('organization_id', $organization->id)->where('name', 'Console de testes')->whereNull('revoked_at')
            ->get()->each->revoke();

        [, $key] = ApiClient::issue($organization, 'Console de testes', ['ai.host_profiles', 'ai.conversations']);
        Storage::put(self::KEY_FILE, Crypt::encryptString($key));

        $api = new InternalApi($key);
        foreach ([
            ['PUT', '/host-profiles/'.DemoBusiness::SLUG, DemoBusiness::profile()],
            ['PUT', '/host-profiles/'.DemoBusiness::SLUG.'/knowledge', ['content' => DemoBusiness::knowledge()]],
            ['PUT', '/host-profiles/'.DemoBusiness::SLUG.'/tools', ['tools' => DemoBusiness::tools()]],
        ] as [$method, $uri, $body]) {
            $response = $api->call($method, $uri, $body);

            if ($response['status'] >= 300) {
                throw new RuntimeException("Falha ao configurar a demonstração em {$uri}: ".json_encode($response['body'], JSON_UNESCAPED_UNICODE));
            }
        }

        return $organization;
    }

    public static function isReady(): bool
    {
        return Storage::exists(self::KEY_FILE);
    }

    private function api(): InternalApi
    {
        if (! self::isReady()) {
            throw new RuntimeException('Demonstração não configurada. Rode: php artisan foxy:demo:setup');
        }

        return new InternalApi(Crypt::decryptString(Storage::get(self::KEY_FILE)));
    }

    public function openConversation(string $userRef, string $role, ?string $model): array
    {
        return $this->api()->call('POST', '/conversations', array_filter([
            'host_profile' => DemoBusiness::SLUG,
            'user_ref' => $userRef,
            'allowed_tools' => DemoBusiness::ROLES[$role],
            'model' => $model,
        ]));
    }

    /**
     * Envia a mensagem e, enquanto a IA pedir tools, executa e devolve os resultados — como faria um sistema real.
     *
     * @return array{reply: ?string, escalate: ?array, trace: list<array>, usage: array, error: ?array, model: ?string}
     */
    public function ask(string $conversationId, string $text, ?string $page = null, ?array $error = null): array
    {
        $api = $this->api();
        $trace = [];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'cost_usd' => 0.0];

        $response = $api->call('POST', "/conversations/{$conversationId}/messages", array_filter([
            'text' => $text,
            'page' => $page,
            'error' => $error,
        ]));

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            if ($response['status'] >= 300) {
                return ['reply' => null, 'escalate' => null, 'trace' => $trace, 'usage' => $usage, 'error' => $response['body']['error'] ?? ['message' => 'Erro'], 'model' => null];
            }

            $body = $response['body'];
            foreach (array_keys($usage) as $field) {
                $usage[$field] += $body['usage'][$field] ?? 0;
            }

            if ($body['status'] === 'answered') {
                return ['reply' => $body['reply'], 'escalate' => $body['escalate'], 'trace' => $trace, 'usage' => $usage, 'error' => null, 'model' => $body['usage']['model'] ?? null];
            }

            $results = [];
            foreach ($body['tool_calls'] as $call) {
                $output = $this->business->run($call['name'], (array) $call['input']);
                $trace[] = ['tool' => $call['name'], 'input' => $call['input'], 'output' => $output];
                $results[] = ['id' => $call['id'], 'output' => $output];
            }

            $response = $api->call('POST', "/conversations/{$conversationId}/tool-results", ['results' => $results]);
        }

        return ['reply' => null, 'escalate' => null, 'trace' => $trace, 'usage' => $usage, 'error' => ['message' => 'Muitas rodadas de ferramentas.'], 'model' => null];
    }
}
