# API da Foxy AI — guia para integrar um sistema

> Para quem vai colocar a Foxy AI dentro de um sistema (o "anfitrião"). Desenho e motivos: `02-foxy-ai.md` e `05-arquitetura.md`.

## 1. Acesso

- Base: `/api/v1`. Toda chamada leva `Authorization: Bearer <chave>`.
- Cada sistema Foxy é uma **integração**; cada cliente dele é uma **organização** (ver `07-planos-permissoes-mascotes.md`, seção 1).
- A chave é criada pela equipe Foxy (`php artisan foxy:api-client:create`) e pode ser:
  - **do sistema** (recomendado): atende todos os clientes; nas rotas de cliente, informe qual com o cabeçalho `X-Foxy-Organization: <id do cliente>`;
  - **presa a um cliente** (`--organization=`): sempre age nesse cliente, sem cabeçalho.
- Escopos: `organizations` (criar/atualizar clientes), `ai.host_profiles` (manual e consultas), `ai.conversations` (conversar e ver uso).

### Clientes (organizações)

```http
POST  /api/v1/organizations        { "name": "Clínica Bem Viver", "external_ref": "id-no-seu-sistema" }  → 201 { "id", ... }
GET   /api/v1/organizations?external_ref=id-no-seu-sistema
PATCH /api/v1/organizations/{id}   { "status": "suspended" }   (ou "active")
```

- Com `external_ref`, o `POST` pode ser chamado sempre (cria na primeira vez, atualiza o nome depois) — não duplica.
- Cliente suspenso → as rotas de cliente respondem `403 organization_suspended`.
- O manual e as consultas (seção 2) são do **sistema** e valem para todos os clientes; conversas, uso e limites são **de cada cliente**.

### Plano de IA e créditos de cada cliente

```http
GET  /api/v1/ai/plans                                   → os seis planos (créditos, capacidades, faixas)
PUT  /api/v1/organizations/{id}/ai-subscription         { "plan": "ia-5000" }   (opcional: "status": "suspended")
GET  /api/v1/organizations/{id}/ai-subscription         → plano, créditos incluídos, extras, usados, disponíveis, % usado
POST /api/v1/organizations/{id}/ai-credits              { "credits": 2000, "reference": "pedido-77" }   (múltiplos de 1.000)
```

- **Créditos:** 1 crédito = US$ 0,001 de custo de IA. Cada resposta consome conforme o custo real (Haiku ~0,5 crédito por pergunta simples).
- **Planos:** `ia-1000`, `ia-3000` (dúvidas de uso e erros, faixa rápida); `ia-5000`, `ia-10000` (+ dados e mensagens, faixas rápida e equilibrada); `ia-25000`, `ia-50000` (tudo, todas as faixas).
- **Créditos extras** valem no mês da compra e se somam à franquia. Créditos acabaram → `429 credit_limit`.
- Cliente sem plano → `403 no_ai_plan`. **Quem cobra o cliente é o sistema**; a Foxy AI só mede e limita.
- Ao abrir a conversa, peça a faixa com `"tier": "rapida" | "equilibrada" | "avancada"` (padrão: a do plano). Faixa fora do plano → `403 tier_not_in_plan`. Planos sem "dados" não oferecem as consultas do sistema (a resposta mostra `allowed_tools` efetivo).
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

## 4. Privacidade e uso

```http
DELETE /api/v1/conversations?user_ref=u-123   → { "deleted": 2 }
GET    /api/v1/usage?month=2026-10            → { "month", "messages", "cost_usd", "by_model": [...] }
```

- Conversas (com os resultados de tools que passaram por elas) ficam **30 dias** e são apagadas automaticamente. Use o `DELETE` quando um usuário pedir exclusão dos dados (LGPD).
- Os registros de uso (tokens e custo, sem texto) ficam para cobrança e relatório.

## 5. Erros que o sistema deve tratar

| HTTP | `code` | O que fazer |
|---|---|---|
| 401 | `unauthenticated` | Chave ausente, errada ou revogada. |
| 400 | `organization_required` | Rota de cliente chamada com chave do sistema sem o cabeçalho `X-Foxy-Organization`. |
| 403 | `forbidden` | Chave sem o escopo da rota, ou chave presa a outro cliente. |
| 403 | `organization_suspended` | Cliente suspenso pelo sistema. |
| 404 | `not_found` / `organization_not_found` | Perfil, conversa ou cliente não existe neste sistema. |
| 409 | `tool_results_pending` | A IA espera resultados: envie em `/tool-results` antes de nova mensagem. |
| 409 | `conversation_full` | Limite de mensagens da conversa (padrão 20): abra outra conversa. |
| 409 | `conversation_busy` | Outra mensagem está sendo processada nesta conversa. |
| 409 | `no_pending_tools` | Resultados enviados sem pedido pendente. |
| 410 | `conversation_expired` | Conversa com mais de 30 dias: abra outra. |
| 422 | `validation_failed`, `knowledge_missing`, `unknown_tools`, `tool_results_mismatch`, `tool_result_too_large` | Corrija a requisição (detalhes em `message`/`details`). |
| 403 | `no_ai_plan` / `tier_not_in_plan` | Cliente sem plano de IA ativo, ou faixa de modelo fora do plano. |
| 429 | `credit_limit` / `rate_limited` | Créditos do mês acabaram (compre extras) ou excesso de mensagens por minuto. |
| 503 | `ai_unavailable` | IA fora do ar: tente de novo. Nada foi gravado; reenviar é seguro. |
