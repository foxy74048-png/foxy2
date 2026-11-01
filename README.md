# Foxy Technology

Nova base da Foxy: ecossistema multi-tenant com um Core e vários produtos separados, começando pela **Foxy AI** (consumida por API por todos os sistemas) e depois o **Foxy Commerce**.

Projeto separado do sistema atual (`C:\Users\mdona\OneDrive\Desktop\Tarot\tarologa`, legado), que continua funcionando sem alterações.

## Estado

Etapas 1–4 aprovadas; implementação da Foxy AI em andamento (Laravel 13, PHP 8.4). Testes: `php artisan test`.

| Etapa | Documento | Status |
|---|---|---|
| 0. Contexto estratégico | [docs/00-contexto-estrategico.md](docs/00-contexto-estrategico.md) | v0.1 |
| 1. Core conceitual | [docs/01-core-conceitual.md](docs/01-core-conceitual.md) | v0.1 aprovado |
| 2. Foxy AI (primeiro produto: assistente do sistema) | [docs/02-foxy-ai.md](docs/02-foxy-ai.md) | v1.0 — B1–B8 aprovadas |
| — Foxy Commerce (segundo produto) | [docs/03-foxy-commerce.md](docs/03-foxy-commerce.md) | rascunho pausado — D6 decidida |
| 3. Recorte da Platform | [docs/04-recorte-platform.md](docs/04-recorte-platform.md) | v1.0 — aprovado |
| 4. Arquitetura concreta | [docs/05-arquitetura.md](docs/05-arquitetura.md) | v1.0 — C1–C4 aprovadas |
| 5. Implementação | [docs/05-arquitetura.md](docs/05-arquitetura.md), seção 10 | em andamento — guia da API: [docs/06-api-foxy-ai.md](docs/06-api-foxy-ai.md) |
| 6. Planos, permissões, mascotes e widget | [docs/07-planos-permissoes-mascotes.md](docs/07-planos-permissoes-mascotes.md) | proposta v0.1 — decisões E1–E8 aguardando aprovação |

Insumo de apoio: [docs/legado-insumos.md](docs/legado-insumos.md) — o que o sistema atual já resolve e o que pode ser aproveitado.

## Operação (comandos)

```bash
php artisan foxy:integration:create "Foxy Agenda"                          # cadastra um sistema (mostra o ID)
php artisan foxy:api-client:create <integration-id> "Nome" --scope=...     # chave do sistema (aparece uma vez só)
php artisan foxy:api-client:create <integration-id> "Nome" --organization=<id>   # chave presa a um cliente
php artisan foxy:api-client:revoke <api-client-id>                # revoga chave
```

Conferir uma chave: `GET /api/v1/me` com `Authorization: Bearer <chave>`.

### Console de testes (`/console`)

```bash
php artisan foxy:demo:setup                         # negócio fictício (Clínica Bem Viver) pronto para conversar
php artisan foxy:console-user:create voce@email.com # acesso da equipe (senha aparece uma vez só)
```

Sem chave da Anthropic, use `FOXY_AI_PROVIDER=fake` no `.env` (respostas de teste). Com a chave, `FOXY_AI_PROVIDER=anthropic` e `ANTHROPIC_API_KEY=...`.

### Rotina e relatórios

```bash
php artisan foxy:ai:usage --month=2026-10   # uso e custo por organização e modelo
php artisan foxy:ai:purge-conversations     # apaga conversas vencidas (agendado todo dia às 03:00)
```

O agendamento depende do cron do Laravel no servidor: `* * * * * php /caminho/artisan schedule:run`.
Limites padrão de uso em `config/foxy.php` (cada organização pode ter os seus em `organizations.limits`).
