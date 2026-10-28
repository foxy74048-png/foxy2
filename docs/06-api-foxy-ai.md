# API da Foxy AI — guia para integrar um sistema

> Para quem vai colocar a Foxy AI dentro de um sistema (o "anfitrião"). Desenho e motivos: `02-foxy-ai.md` e `05-arquitetura.md`.

## 1. Acesso

- Base: `/api/v1`. Toda chamada leva `Authorization: Bearer <chave>`.
- A chave é criada pela equipe Foxy (`php artisan foxy:api-client:create`) e define a organização. Escopos: `ai.host_profiles` (configurar) e `ai.conversations` (conversar).
- Toda resposta traz `request_id` (no corpo e no cabeçalho `X-Request-Id`). Informe-o ao suporte em caso de problema.
- Erros sempre no formato `{ "error": { "code": "...", "message": "...", "request_id": "..." } }`.

## 2. Configurar o sistema (uma vez, e a cada mudança)

```http
PUT /api/v1/host-profiles/agenda-demo
{ "name": "Agenda Demo", "description": "Agenda de atendimentos para clínicas", "tone": "Simpático, trate por você." }

PUT /api/v1/host-profiles/agenda-demo/knowledge
{ "content": "## Remarcar atendimento\nAgenda › clique no atendimento › Remarcar ..." }

PUT /api/v1/host-profiles/agenda-demo/tools
{ "tools": [
  { "name": "financeiro_saldos_em_aberto",
    "description": "Clientes com valor em aberto no período, do maior para o menor.",
    "risk": "read",
    "permission": "financeiro.ver",
    "input_schema": { "type": "object",
      "properties": {
        "periodo": { "type": "string", "description": "Mês no formato AAAA-MM" },
        "limite":  { "type": "integer", "minimum": 1, "maximum": 50 } },
      "required": ["periodo"] } } ] }
```

- Conhecimento e catálogo são **versionados**: conteúdo igual não gera versão nova. Conversas abertas continuam com a versão que tinham.
- Regras do catálogo: nome com letras minúsculas, números e `_` (prefixo `foxy_` é reservado); `risk` só pode ser `"read"`; tipos aceitos: `string`, `integer`, `number`, `boolean`, `array` (de tipos simples); palavras aceitas: `type`, `description`, `enum`, `minimum`, `maximum`, `maxLength`, `items`, `maxItems`. Campos fora do schema são recusados.
- `permission` é informativo: **quem garante a permissão é o sistema**, ao montar `allowed_tools` e ao executar.

## 3. Conversar

```http
POST /api/v1/conversations
{ "host_profile": "agenda-demo", "user_ref": "u-123", "allowed_tools": ["financeiro_saldos_em_aberto"] }
→ 201 { "conversation_id": "01J...", "expires_at": "...", ... }
```

- `user_ref`: identificador do usuário **no seu sistema** (opaco; não mande nome nem e-mail).
- `allowed_tools`: só as tools que **esse usuário** pode usar. A IA nem vê as outras.
- `model` (opcional): um de `claude-opus-5-5`, `claude-sonnet-5-5`, `claude-haiku-5-5`. Sem informar, vale o padrão da Foxy. Fica fixo na conversa.

```http
POST /api/v1/conversations/{id}/messages
{ "text": "Quem mais me deve este mês?",
  "page": "Financeiro › Recebimentos",
  "error": { "message": "Horário indisponível", "code": "AG-409", "action": "Salvar atendimento às 14h" } }
```

`page` e `error` são opcionais. A resposta é uma de duas:

```json
{ "status": "answered", "reply": "Quem mais deve é a Ana (R$ 320,00)...", "escalate": null, "usage": {...}, "request_id": "..." }
```

```json
{ "status": "tool_requested",
  "tool_calls": [{ "id": "toolu_1", "name": "financeiro_saldos_em_aberto", "input": { "periodo": "2026-10", "limite": 5 } }],
  "usage": {...}, "request_id": "..." }
```

Em `tool_requested`, execute cada tool **com as permissões do usuário** e devolva todos os resultados de uma vez:

```http
POST /api/v1/conversations/{id}/tool-results
{ "results": [{ "id": "toolu_1", "output": [{ "cliente": "Ana", "em_aberto": 320.0 }] }] }
```

- `output`: qualquer JSON (até 30 mil caracteres). Devolva **só os campos necessários** (nada de CPF, telefone, endereço).
- Se não puder executar: `{ "id": "toolu_1", "output": "Sem permissão", "is_error": true }` — a IA explica à pessoa.
- A resposta pode ser `answered` ou um novo `tool_requested` (até 4 rodadas por mensagem).

**Encaminhar ao suporte:** quando `escalate` vier preenchido (`{ "reason": "defeito" | "sem_resposta" | "pedido_da_pessoa", "summary": "..." }`), abra o chamado no seu canal (WhatsApp, e-mail...) com o resumo.

## 4. Erros que o sistema deve tratar

| HTTP | `code` | O que fazer |
|---|---|---|
| 401 | `unauthenticated` | Chave ausente, errada ou revogada. |
| 403 | `forbidden` | Chave sem o escopo da rota. |
| 404 | `not_found` | Perfil ou conversa não existe (ou é de outra organização). |
| 409 | `tool_results_pending` | A IA espera resultados: envie em `/tool-results` antes de nova mensagem. |
| 409 | `conversation_full` | Limite de mensagens da conversa (padrão 20): abra outra conversa. |
| 409 | `conversation_busy` | Outra mensagem está sendo processada nesta conversa. |
| 409 | `no_pending_tools` | Resultados enviados sem pedido pendente. |
| 410 | `conversation_expired` | Conversa com mais de 30 dias: abra outra. |
| 422 | `validation_failed`, `knowledge_missing`, `unknown_tools`, `tool_results_mismatch`, `tool_result_too_large` | Corrija a requisição (detalhes em `message`/`details`). |
| 429 | `usage_limit` / `rate_limited` | Limite do mês ou excesso de mensagens por minuto. |
| 503 | `ai_unavailable` | IA fora do ar: tente de novo. Nada foi gravado; reenviar é seguro. |
