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
| 5. Implementação | [docs/05-arquitetura.md](docs/05-arquitetura.md), seção 10 | em andamento |

Insumo de apoio: [docs/legado-insumos.md](docs/legado-insumos.md) — o que o sistema atual já resolve e o que pode ser aproveitado.
