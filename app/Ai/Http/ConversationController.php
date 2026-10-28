<?php

namespace App\Ai\Http;

use App\Ai\Assistant\Assistant;
use App\Ai\Conversations\Conversation;
use App\Ai\HostProfiles\HostProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController
{
    public function __construct(private Assistant $assistant) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host_profile' => ['required', 'string'],
            'user_ref' => ['required', 'string', 'max:120'],
            'allowed_tools' => ['nullable', 'array', 'max:30'],
            'allowed_tools.*' => ['string'],
        ]);

        $profile = HostProfile::where('slug', $data['host_profile'])->firstOrFail();
        $conversation = $this->assistant->open($profile, $data['user_ref'], $data['allowed_tools'] ?? []);

        return response()->json([
            'conversation_id' => $conversation->getKey(),
            'model' => $conversation->model,
            'knowledge_version' => $conversation->knowledge_version,
            'tools_version' => $conversation->tools_version,
            'allowed_tools' => $conversation->allowed_tools,
            'expires_at' => $conversation->expires_at->toIso8601String(),
        ], 201);
    }

    public function message(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:4000'],
            'page' => ['nullable', 'string', 'max:200'],
            'error' => ['nullable', 'array:message,code,action'],
            'error.message' => ['required_with:error', 'string', 'max:500'],
            'error.code' => ['nullable', 'string', 'max:60'],
            'error.action' => ['nullable', 'string', 'max:200'],
        ]);

        $result = $this->assistant->reply(Conversation::findOrFail($id), $data['text'], $data['page'] ?? null, $data['error'] ?? null);

        return response()->json($result->toArray());
    }

    public function toolResults(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'results' => ['required', 'array', 'min:1', 'max:20'],
            'results.*.id' => ['required', 'string', 'max:100'],
            'results.*.output' => ['present'],
            'results.*.is_error' => ['sometimes', 'boolean'],
        ]);

        $result = $this->assistant->submitToolResults(Conversation::findOrFail($id), $data['results']);

        return response()->json($result->toArray());
    }
}
