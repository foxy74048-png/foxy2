<?php

namespace App\Platform\Http;

use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use App\Platform\Organizations\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * O sistema (integração) cria e mantém os seus clientes. Sempre dentro da própria integração.
 */
class OrganizationController
{
    /**
     * Cria o cliente; com external_ref já existente, atualiza o nome (pode ser chamado sempre, sem duplicar).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'external_ref' => ['nullable', 'string', 'max:120'],
        ]);

        $organization = isset($data['external_ref'])
            ? $this->query()->firstOrNew(['external_ref' => $data['external_ref']])
            : new Organization;

        $created = ! $organization->exists;
        $organization->fill(['name' => $data['name'], 'integration_id' => $this->integrationId()])->save();

        Audit::record($created ? 'platform.organization_created' : 'platform.organization_updated', 'ok', $organization);

        if ($created) {
            app(\App\Ai\Promotions\PromotionEngine::class)->apply();
        }

        return response()->json($this->present($organization), $created ? 201 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['external_ref' => ['nullable', 'string', 'max:120']]);

        $organizations = $this->query()
            ->when($data['external_ref'] ?? null, fn ($q, $ref) => $q->where('external_ref', $ref))
            ->orderBy('name')
            ->limit(100)
            ->get();

        return response()->json(['data' => $organizations->map(fn ($o) => $this->present($o))]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->present($this->query()->findOrFail($id)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $organization = $this->query()->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
        ]);

        $organization->fill($data)->save();
        Audit::record('platform.organization_updated', 'ok', $organization, array_intersect_key($data, ['status' => true]));

        return response()->json($this->present($organization));
    }

    private function query()
    {
        return Organization::where('integration_id', $this->integrationId());
    }

    private function integrationId(): string
    {
        return ExecutionContext::current()->integration->getKey();
    }

    private function present(Organization $organization): array
    {
        return [
            'id' => $organization->getKey(),
            'name' => $organization->name,
            'external_ref' => $organization->external_ref,
            'status' => $organization->status,
        ];
    }
}
