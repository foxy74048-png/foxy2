# Etapa 3 — Recorte da Platform para a Foxy AI

> **Status:** v1.0 — derivado de `02-foxy-ai.md` v1.0 (2026-10-28)
> **Regra:** só entra o que a Foxy AI usa agora. O resto fica no papel (Core conceitual) até um produto precisar.

## 1. O que será construído

| Capacidade | Nível | O que existe de verdade | O que fica só como contrato |
|---|---|---|---|
| **Organization** | N1 | Tabela `organizations`, criada por comando. Todo dado de negócio tem `organization_id`. | Tela de cadastro, várias por usuário. |
| **API Clients** | N1 | Credencial por organização: chave com hash, escopos, revogação, último uso. | Autoatendimento, OAuth. |
| **Execution Context** | N1 | Objeto montado em todo request/job: `principal`, `organization`, `on_behalf_of` (`user_ref`), `correlation_id`, `source`. Nada de dado de negócio sem ele. | `membership` (não há usuários nesta fase). |
| **Entitlements** | N1 (só uso) | Medidor mensal por organização e por `user_ref`, com limite configurável. Interface `canUse()` / `recordUsage()`. | Planos e features — `hasFeature()` responde "liberado" (N0). |
| **Audit** | N1 | Tabela só-inclusão: quem, em nome de quem, o quê, resultado, `correlation_id`. | Tela de consulta (por enquanto, comando/relatório). |
| **Identity / Membership / Authorization** | N0 | Só a equipe Foxy entra no console de testes (login simples de administrador). | Usuários de clientes, papéis, convites — entram com o Commerce. |
| **Rate limit** | N1 | Por API Client e por `user_ref`. | — |

**Fora deste recorte:** Plans, Subscription, Billing, Events (barramento), Files, Notifications, Feature flags.

## 2. Como cada peça fica preparada para crescer

- **Organization em tudo:** escopo global por `organization_id` nos models de negócio, com teste que falha se uma consulta "vazar" para outra organização.
- **Entitlements por interface:** a Foxy AI chama `canUse()` / `recordUsage()`. Quando houver planos, muda a implementação, não quem chama.
- **Execution Context explícito:** quando entrarem usuários e papéis, o contexto ganha `membership` e as checagens já passam por ele.
- **Audit só-inclusão desde o primeiro dia:** nunca precisa "migrar histórico" depois.

## 3. Critério de pronto da etapa 3

- [x] Cada capacidade com nível definido e motivo (tabela acima).
- [x] Nada da lista "fora" é necessário para a seção 6 de `02-foxy-ai.md` funcionar.
- [ ] Aprovação junto com a etapa 4.
