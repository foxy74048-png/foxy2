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

## Relação com o sistema legado (`C:\Users\mdona\OneDrive\Desktop\Tarot\tarologa`)

- O legado **não é alterado** por este projeto. Repositórios, bancos e deploys separados.
- Este projeto **nunca** lê o banco nem importa código do legado em tempo de execução.
- Se um dia houver integração, é o legado que consome a **API pública deste projeto** — nunca o contrário.
- Reaproveitar código do legado = copiar e adaptar uma peça específica (com teste), só quando ela servir à nova arquitetura. Ver `docs/legado-insumos.md`.

## Processo de definição (em andamento)

1. Core conceitual — `docs/01-core-conceitual.md`
2. Foxy AI — primeiro produto (`docs/02-foxy-ai.md`): assistente que entende o sistema onde é colocado (suporte de uso, erros e perguntas sobre os dados via tools de leitura), consumida só por API; o Foxy Commerce (`docs/03-foxy-commerce.md`) vem em seguida
3. Recorte mínimo da Platform a implementar junto com a Foxy AI — `docs/04-recorte-platform.md`
4. Arquitetura concreta (módulos, tenancy, banco, APIs, eventos, auth, infra, stack) — `docs/05-arquitetura.md`
5. Implementação — **somente após aprovação explícita da etapa 4**

Princípio: definir o Core conceitualmente, implementar só o Core necessário para o primeiro produto, boundaries preparados para evolução.
