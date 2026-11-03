# Foxy Technology — instruções para IA de desenvolvimento

Leia `docs/00-contexto-estrategico.md` antes de decisões de arquitetura. Resumo das regras que mais importam no dia a dia:

- **Arquitetura:** modular monolith, limites de domínio claros, APIs e eventos internos entre módulos. Sem microserviços sem necessidade real.
- **Multi-tenancy desde o início:** `Organization` é a unidade central; todo dado de negócio pertence a uma organização e todo acesso é filtrado por ela.
- **Independência de produtos:** um produto nunca acessa tabelas/código interno de outro; só contratos (API, eventos, Platform).
- **Platform mínima:** só promover algo para a Platform quando houver necessidade real de compartilhamento.
- **Mudanças mínimas:** a menor mudança que resolve corretamente. Nada de refatorações, dependências, frameworks ou funcionalidades não solicitadas.
- **Fluxo:** analisar → diagnosticar → propor → **aguardar aprovação** para mudanças arquiteturais/significativas → implementar → testar → verificar regressões.
- **IA no produto:** age só via tools com checagem de permissão, regra de negócio e auditoria. Nunca acesso direto ao banco.
- **Segurança/LGPD:** segredos fora do repositório, coletar só o necessário, auditar ações relevantes.
- **Legado:** o sistema antigo não define a nova arquitetura.

## Daqui para frente (decisão de 2026-11-03)

- **Tudo o que for desenvolvido usa o Core da foxy2.** O Foxy Studio (legado, `tarologa`) só recebe melhorias e fala com o Core por API.
- Foxy Admin (`admin.foxy.net.br`) e vitrine da marca (`foxy.net.br`) moram aqui — ver `docs/08-foxy-admin-e-vitrine.md`.

## Relação com o sistema legado (`C:\Users\mdona\OneDrive\Desktop\Tarot\tarologa`)

- O legado **não é alterado** por este projeto. Repositórios, bancos e deploys separados.
- Este projeto **nunca** lê o banco nem importa código do legado em tempo de execução.
- Se um dia houver integração, é o legado que consome a **API pública deste projeto** — nunca o contrário.
- Reaproveitar código do legado = copiar e adaptar uma peça específica (com teste), só quando ela servir à nova arquitetura. Ver `docs/legado-insumos.md`.

## Processo de definição

1. Core conceitual — `docs/01-core-conceitual.md`
2. Foxy AI — primeiro produto (`docs/02-foxy-ai.md`): assistente que entende o sistema onde é colocado (suporte de uso, erros e perguntas sobre os dados via tools de leitura), consumida só por API; o Foxy Commerce (`docs/03-foxy-commerce.md`) vem em seguida
3. Recorte mínimo da Platform a implementar junto com a Foxy AI — `docs/04-recorte-platform.md`
4. Arquitetura concreta (módulos, tenancy, banco, APIs, eventos, auth, infra, stack) — `docs/05-arquitetura.md`
5. Implementação — concluída (`docs/05-arquitetura.md` seção 10 e `docs/07-planos-permissoes-mascotes.md` seção 8); em produção em https://ai.foxy.net.br
6. Primeiro anfitrião real: o Foxy Studio (o sistema atual, studio.foxy.net.br), consumindo a API pelo pacote `packages/foxy-ai-client` (decisão B7)

Princípio: definir o Core conceitualmente, implementar só o Core necessário para o primeiro produto, boundaries preparados para evolução.

## Desenvolvimento

- PHP 8.4 + Laravel 13. Nesta máquina: `C:\Users\mdona\php84\php.exe artisan test` (o `php` do PATH é 8.1).
- Todo ajuste com teste em `tests/Feature`; rodar `php artisan test` inteiro antes de concluir.
- Migrations aditivas e compatíveis com PostgreSQL (produção) e SQLite (testes). O CI roda nos dois.
- Nenhum teste chama a Anthropic de verdade: usar o `FakeProvider`.
- Commits em português, descrevendo a mudança. Mudou módulo ou contrato → atualizar o doc correspondente no mesmo commit.

## Visual (Core)

- Toda tela nova usa o Core: `<x-core::layout>` (menu lateral + conteúdo) ou `<x-core::plain>` (login/avisos), CSS em `public/core/foxy-core.css`.
- Roxo sóbrio (`--primary` #5b3fd1), neutro e profissional; nada de lilás/rosa/creme; alertas em verde/vermelho de verdade; opções importantes sempre visíveis.
- A janela da raposa é `public/core/foxy-assistant.js` + `.css`; sistemas integram pelo pacote `packages/foxy-ai-client` (ver `docs/06-api-foxy-ai.md`, seção 3.2).
