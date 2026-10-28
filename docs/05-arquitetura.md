# Etapa 4 — Arquitetura concreta (Foxy AI + Platform mínima)

> **Status:** v1.0 — C1–C4 aprovadas em 2026-10-28 (seção 11). Implementação em andamento (seção 10).
> **Critério do responsável (2026-10-28):** melhor desempenho e menor custo no início, com tudo preparado para crescer em grande escala.
> **Base:** `02-foxy-ai.md` v1.0 e `04-recorte-platform.md` v1.0.

## 1. Resumo

| Item | Escolha | Por quê |
|---|---|---|
| Linguagem / framework | **PHP 8.4 + Laravel 13** | A equipe já domina; padrões provados no legado podem ser copiados e adaptados; há SDK oficial da Anthropic para PHP; um só framework para API, console e (depois) o Commerce. |
| Banco | **PostgreSQL** | Já é o banco de produção da Foxy; escala bem; migrations também rodam em SQLite nos testes. |
| Cache, filas, rate limit | **Driver `database`** no início | Zero serviço extra. Troca para Redis por configuração quando houver mais de uma máquina com tráfego alto. |
| Provedor de IA | **Anthropic (Claude)**, via SDK oficial `anthropic-ai/sdk` | Qualidade em português, uso de tools confiável, cache de prompt barato (seção 6). |
| Hospedagem | **O EC2 que já existe**, em pasta, banco, usuário de banco e pool PHP-FPM próprios (decisão C3) | Custo extra zero. Continua sendo outro deploy e outro banco, sem nada compartilhado com o legado além da máquina. |
| Arquitetura | **Monolito modular** | Conforme `00-contexto-estrategico.md`, seção 17. |

**Alternativa avaliada e descartada:** Node/TypeScript seria melhor para milhares de conexões esperando a IA ao mesmo tempo, mas traria uma segunda stack para manter. Com o contrato da seção 5 de `02-foxy-ai.md` (cada chamada à API faz uma só chamada ao modelo, sem streaming), o PHP atende bem e escala colocando mais máquinas (seção 8).

## 2. Estrutura de pastas (módulos)

```text
app/
├── Platform/                       ← Core mínimo (etapa 3)
│   ├── Organizations/              Organization, escopo por organization_id
│   ├── ApiClients/                 credenciais (hash), escopos, middleware de autenticação
│   ├── Context/                    ExecutionContext (montado por middleware/job)
│   ├── Entitlements/               interface Entitlements + medidor de uso mensal
│   └── Audit/                      AuditEntry só-inclusão + serviço Audit::record()
│
└── Ai/                             ← produto Foxy AI
    ├── Http/                       controllers da API /v1 e do console
    ├── HostProfiles/               perfil, KnowledgePack e ToolCatalog versionados
    ├── Conversations/              Conversation, Message (retenção de 30 dias)
    ├── Assistant/                  orquestra uma rodada: contexto → modelo → tool pedida ou resposta
    ├── Providers/                  interface ModelProvider + AnthropicProvider + FakeProvider (testes)
    └── Usage/                      UsageRecord, cálculo de custo, relatório
```

Regras:
- `Ai` só usa `Platform` pelas interfaces públicas (`ExecutionContext`, `Entitlements`, `Audit`). `Platform` não conhece `Ai`.
- Nada fora de `Ai/Providers` fala com a Anthropic. Trocar de provedor = nova classe que implementa `ModelProvider`.
- O host de demonstração (`02-foxy-ai.md`, seção 12) fica em `app/Demo/` e fala com a Foxy AI **só pela API**, como faria um sistema de verdade.

## 3. Banco de dados (tabelas)

| Tabela | Campos principais | Observação |
|---|---|---|
| `organizations` | id, name, status | |
| `api_clients` | organization_id, name, key_hash, scopes, revoked_at, last_used_at | Chave mostrada uma vez só, na criação. |
| `host_profiles` | organization_id, slug, name, tone, current_knowledge_version, current_tools_version | |
| `knowledge_packs` | host_profile_id, version, content (texto), tokens_estimated | Versões antigas ficam guardadas. |
| `tool_catalogs` | host_profile_id, version, tools (json: nome, descrição, schema, permissão, risco) | Só `risco = leitura` é aceito nesta fase. |
| `conversations` | organization_id, host_profile_id, user_ref, allowed_tools, expires_at | Apagada após 30 dias (job diário). |
| `conversation_messages` | conversation_id, role, content (json), created_at | Guarda o histórico no formato do provedor para o cache funcionar. |
| `usage_records` | organization_id, user_ref, conversation_id, model, tokens (entrada, cache, saída), cost_usd, request_id | Não guarda texto. |
| `usage_counters` | organization_id, user_ref (nullable), month, requests, cost_usd | Base do limite mensal; atualização atômica. |
| `audit_entries` | organization_id, principal, on_behalf_of, action, subject, result, correlation_id, created_at | Sem update/delete. |

Todas com `organization_id` indexado (exceto `organizations`). IDs ULID, que podem ser gerados em várias máquinas sem conflito.

## 4. API (`/api/v1`)

| Método | Rota | Para quê |
|---|---|---|
| PUT | `/host-profiles/{slug}/knowledge` | Anfitrião publica nova versão do conhecimento |
| PUT | `/host-profiles/{slug}/tools` | Anfitrião publica nova versão do catálogo de tools |
| POST | `/conversations` | Abre conversa (`host_profile`, `user_ref`, `allowed_tools`) |
| POST | `/conversations/{id}/messages` | Mensagem do usuário (+ `page`, `error`) → `answered` ou `tool_requested` |
| POST | `/conversations/{id}/tool-results` | Anfitrião devolve resultado das tools → `answered` ou nova `tool_requested` |
| DELETE | `/conversations?user_ref=` | Apaga as conversas de um usuário (LGPD) |
| GET | `/usage?month=` | Uso e custo do mês da organização |

- Autenticação: `Authorization: Bearer <chave>`; a organização vem da chave, nunca do corpo.
- Erros num formato único: `{ "error": { "code", "message", "request_id" } }`. Limite estourado → `429` com `code: usage_limit`.
- Toda resposta traz `request_id` (o `correlation_id` do contexto).

## 5. Uma rodada do assistente (`Ai/Assistant`)

1. Middleware autentica a chave → monta o `ExecutionContext` → rate limit.
2. `Entitlements::canUse(org, user_ref)` → senão, `429`.
3. Monta a requisição ao modelo **sempre na mesma ordem**, para o cache funcionar:
   `tools` (catálogo permitido, ordem fixa, `strict: true`) → `system` (regras Foxy + perfil + conhecimento, **ponto de cache aqui**) → histórico → mensagem nova com tela/erro.
4. Chama o `ModelProvider` (timeout de 60 s, até 2 novas tentativas automáticas do SDK).
5. Se o modelo pediu tool: confere se ela está em `allowed_tools` e se a entrada valida no schema → grava e devolve `tool_requested`. Tool fora da lista = erro registrado na auditoria, nunca repassado.
6. Se respondeu: grava a mensagem e devolve `answered` (com `escalate`, se houver).
7. Sempre: `UsageRecord` + `recordUsage()` + `AuditEntry`.
8. Limite de **4 rodadas de tool por mensagem**; passou disso, a IA responde com o que tem.

## 6. Modelo de IA e custo

Preços da Anthropic em dólar por milhão de tokens (consultados em 2026-10-28):

| Modelo | Entrada | Saída | Leitura de cache |
|---|---|---|---|
| Claude Opus 5.5 (`claude-opus-5-5`) | 4,00 | 20,00 | 0,20 |
| Claude Sonnet 5.5 (`claude-sonnet-5-5`) | 2,00 | 10,00 | 0,20 |
| Claude Haiku 5.5 (`claude-haiku-5-5`) | 0,10 | 0,50 | ~0,01 |

**Estimativa por mensagem de suporte** (manual de ~8 mil tokens + regras e tools de ~2 mil, em cache; ~1,5 mil tokens novos; ~400 de resposta):

| Modelo | Custo aproximado por mensagem |
|---|---|
| Opus 5.5 (esforço `low`) | ~US$ 0,016 |
| Sonnet 5.5 | ~US$ 0,009 |
| Haiku 5.5 | ~US$ 0,0005 |

Perguntas sobre dados fazem ~2 chamadas (pedido de tool + resposta), ou seja, cerca do dobro. **São estimativas**: o custo real vem do console (`usage_records`).

**O que mantém o custo baixo desde o início:**
- **Cache de prompt:** regras + conhecimento + tools ficam num prefixo fixo. Releituras custam de 5% a 10% do preço normal; o cache dura 5 minutos e se renova a cada uso.
- **Esforço baixo** (`effort: low`): é suporte e consulta, não raciocínio pesado.
- **Histórico curto:** só as últimas mensagens da conversa vão ao modelo.
- **O modelo é configuração** (`FOXY_AI_MODEL`, `FOXY_AI_EFFORT`) e pode ser diferente por perfil de anfitrião, sem mudar código.

**Escolha do modelo (C2):** começar pelo Opus 5.5 com esforço `low` como referência de qualidade e, no console, rodar as mesmas perguntas no Sonnet 5.5 e no Haiku 5.5. Você escolhe o modelo padrão vendo qualidade e custo lado a lado.

## 7. Segurança

- Chaves de API: guardadas só como hash (SHA-256), mostradas uma vez, com escopo e revogação.
- Chave da Anthropic e segredos só no `.env` do servidor, nunca no repositório.
- Conhecimento e resultados de tools vão ao modelo marcados como **dados**; as regras da Foxy dizem que nada ali é instrução.
- Tools validadas no schema e na lista permitida antes de voltar ao anfitrião.
- Escopo por `organization_id` em todo model de negócio, com teste de isolamento entre organizações.
- Conversas apagadas automaticamente após 30 dias; rota de exclusão por `user_ref`.
- HTTPS obrigatório; rate limit por chave e por `user_ref`.

## 8. Como cresce (sem reescrever)

| Quando | O que muda | Mudança de código? |
|---|---|---|
| Início | 1 EC2 (o atual), PHP-FPM, banco e filas no PostgreSQL | — |
| O pool da Foxy AI começa a disputar a máquina com o legado | Instância própria (ou a do legado sobe de tamanho) | Não — só deploy |
| Muitas requisições simultâneas | Várias instâncias atrás de um balanceador. A app não guarda estado local (sessão, cache e filas no banco ou no Redis). | Não — só configuração |
| Banco vira gargalo | Banco gerenciado e/ou réplica de leitura para relatórios | Não |
| Filas pesadas (ex.: limpeza, relatórios) | Redis + workers dedicados | Não — troca de driver |
| Respostas longas pedem streaming | Endpoint SSE adicional | Sim, aditiva (nova rota) |
| Volume justificar | Foxy AI com deploy próprio (já é um módulo isolado com API própria) | Pouca — mover o módulo |

O que garante isso desde o primeiro dia: app sem estado local, IDs ULID, `organization_id` em tudo, provedor atrás de interface, limites e uso por interface (`Entitlements`).

## 9. Qualidade e operação

- **Testes** (`tests/Feature`) com `FakeProvider`: nenhum teste chama a Anthropic de verdade. Cobrem: autenticação por chave, isolamento entre organizações, limite de uso, tool fora da lista, limite de rodadas, retenção de 30 dias e o fluxo completo pedir tool → resultado → resposta.
- Migrations aditivas, compatíveis com PostgreSQL e SQLite.
- Logs estruturados com `correlation_id`; relatório de uso e custo por comando (`php artisan ai:usage`).
- Avaliação: um conjunto fixo de perguntas do host de demonstração, rodado no console a cada mudança de prompt ou de modelo.

## 10. Ordem de implementação (após aprovação)

Cada fatia é entregue com testes e commit próprio:

1. ✔ **Esqueleto:** Laravel 13, PostgreSQL/SQLite, estrutura de módulos, CI de testes.
2. ✔ **Platform mínima:** Organization, ApiClient (+ comando para criar), ExecutionContext, Entitlements (uso), Audit.
3. **Foxy AI — suporte de uso:** HostProfile + KnowledgePack, conversa, `AnthropicProvider` com cache, `FakeProvider`, uso e custo.
4. **Ajuda com erros:** campo `error`, sinal `escalate`.
5. **Perguntas sobre dados:** ToolCatalog, `tool_requested` / `tool-results`, validações e limite de rodadas.
6. **Host de demonstração + console de testes:** negócio fictício, tools de leitura, tela que mostra resposta, tools pedidas, tokens e custo, com comparação entre modelos.
7. **Operação:** retenção de 30 dias, relatório de uso, exclusão por `user_ref`.

**Ações que só você pode fazer** (ficam para o fim, conforme combinado): criar o repositório privado no GitHub, criar a conta/chave na Anthropic (com o limite de gasto mensal definido no painel deles), apontar o subdomínio e liberar o deploy no servidor.

## 11. Decisões (aprovadas em 2026-10-28)

| # | Decisão | Recomendação |
|---|---|---|
| **C1** | Stack | PHP 8.4 + Laravel 13 + PostgreSQL, monolito modular (seções 1–2). |
| **C2** | Modelo padrão | Opus 5.5 com esforço `low` como referência; Sonnet 5.5 e Haiku 5.5 comparados no console; você decide o padrão com os números na mão. |
| **C3** | Onde roda no início | No EC2 atual, com pasta, banco, usuário de banco e pool PHP-FPM próprios (limite de processos para não afetar o legado). Instância própria quando o uso pedir. |
| **C4** | Streaming | Não na v1 (respostas de suporte são curtas); entra depois como rota adicional. |

Todas aprovadas como recomendado.
