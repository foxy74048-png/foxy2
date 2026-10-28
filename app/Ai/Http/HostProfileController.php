<?php

namespace App\Ai\Http;

use App\Ai\HostProfiles\HostProfile;
use App\Ai\HostProfiles\ToolSchema;
use App\Platform\Audit\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HostProfileController
{
    public function upsert(Request $request, string $slug): JsonResponse
    {
        abort_unless(preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $slug) === 1, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'tone' => ['nullable', 'string', 'max:500'],
        ]);

        $profile = HostProfile::firstOrNew(['slug' => $slug]);
        $created = ! $profile->exists;
        $profile->fill($data)->save();

        Audit::record($created ? 'ai.host_profile_created' : 'ai.host_profile_updated', 'ok', $profile);

        return response()->json($this->present($profile), $created ? 201 : 200);
    }

    public function show(string $slug): JsonResponse
    {
        return response()->json($this->present($this->find($slug)));
    }

    public function putKnowledge(Request $request, string $slug): JsonResponse
    {
        $profile = $this->find($slug);
        $data = $request->validate(['content' => ['required', 'string', 'max:200000']]);

        $before = $profile->current_knowledge_version;
        $pack = $profile->publishKnowledge($data['content']);

        if ($pack->version !== $before) {
            Audit::record('ai.knowledge_published', 'ok', $profile, ['version' => $pack->version]);
        }

        return response()->json([
            'version' => $pack->version,
            'changed' => $pack->version !== $before,
        ]);
    }

    public function putTools(Request $request, string $slug): JsonResponse
    {
        $profile = $this->find($slug);
        $request->validate(['tools' => ['present', 'array']]);

        [$tools, $errors] = ToolSchema::normalizeCatalog($request->input('tools'));

        if ($errors !== []) {
            throw ValidationException::withMessages(['tools' => $errors]);
        }

        $before = $profile->current_tools_version;
        $catalog = $profile->publishTools($tools);

        if ($catalog->version !== $before) {
            Audit::record('ai.tools_published', 'ok', $profile, ['version' => $catalog->version, 'tools' => $catalog->names()]);
        }

        return response()->json([
            'version' => $catalog->version,
            'changed' => $catalog->version !== $before,
            'tools' => $catalog->names(),
        ]);
    }

    private function find(string $slug): HostProfile
    {
        return HostProfile::where('slug', $slug)->firstOrFail();
    }

    private function present(HostProfile $profile): array
    {
        return [
            'slug' => $profile->slug,
            'name' => $profile->name,
            'description' => $profile->description,
            'tone' => $profile->tone,
            'knowledge_version' => $profile->current_knowledge_version,
            'tools_version' => $profile->current_tools_version,
        ];
    }
}
