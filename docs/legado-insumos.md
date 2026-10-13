# Insumos do sistema legado (`../tarologa`)

> **Atualização 2026-10-13:** o sistema atual será o **primeiro consumidor da API da Foxy AI** (ver `02-foxy-ai.md`, seção 10).
>
> **Status:** levantamento somente leitura, 2026-10-13. **Não é decisão** — é insumo para D4, D5, D6, D9 (etapa 2) e para as etapas 3 e 4.
> **Regra:** o legado não define a nova arquitetura (contexto, seções 32–33). Aqui só se registra o que ele já aprendeu e o que pode ser copiado/adaptado.

---

## 1. O que o legado é

- SaaS multi-tenant para **profissionais de serviço** (saúde, terapias, beleza, estúdios): agenda, clientes, anamnese/formulários, orçamentos, financeiro, cobranças, produtos e estoque, vendas, página pública, WhatsApp.
- **Stack:** Laravel 13, PHP 8.4, monolito Blade (CSS inline, sem framework JS), PostgreSQL na própria EC2, S3 para arquivos, fila do Laravel, Stripe (assinatura da Foxy), Evolution API (WhatsApp), login Google, deploy manual por script na AWS.
- **Tenant:** não existe entidade Organization. O tenant é o **usuário dono** (`workspace_id` = id do dono), com membros de equipe presos a ele. Um usuário não participa de mais de uma conta.
- **Acesso:** planos com *capabilities* e limites (`plan.capability:x`, `hasCapability`), permissões de equipe por checkbox (`team.permission:x`), backoffice `/admin`.
- Cliente final nunca faz login — só links públicos e WhatsApp.

## 2. Respostas sugeridas para as decisões pendentes

| # | Sugestão a partir do legado |
|---|---|
| **D6** | ✔ **Decidido em 2026-10-13: legado separado, sem migração; um Core e vários produtos separados daqui para frente.** Contexto da sugestão: o legado atende **serviços com agendamento**, que o Commerce coloca explicitamente **fora** do público inicial. Logo, **não há migração natural** de clientes do legado para o Commerce. Sugestão: legado segue vivo e independente; um futuro produto "Foxy Serviços/Agenda" no novo ecossistema pode, aí sim, ser o destino desses clientes. Se algum dia o legado precisar de algo do novo, consome a **API pública do novo** — nunca o contrário. |
| **D9** | Toda a base existente e o conhecimento acumulado estão em **Laravel/PHP + PostgreSQL + AWS**. Pelo critério de novas tecnologias (seção 36: curva de aprendizado, impacto na equipe), o ponto de partida natural para a etapa 4 é **manter Laravel + PostgreSQL**, mudando a *arquitetura* (Organization, módulos, Execution Context, Entitlements), não a linguagem. Frontend (Blade vs. API + SPA) fica para a etapa 4. |
| **D4** | ⚠️ **Conflito com uma decisão já tomada no legado.** Lá, uma integração de Pix (Asaas) com **uma única conta** foi construída e **removida**: o dinheiro de todos os clientes cairia na conta da Foxy, tornando-a intermediária financeira com repasse manual. O Payments do Commerce (comprador → lojista) tem exatamente esse risco. Para o MVP só há dois caminhos coerentes: **(a)** cada lojista conecta **a própria conta** do provedor (subconta/marketplace/split — ex.: subcontas Asaas, recebedores Pagar.me, OAuth do Mercado Pago), com o dinheiro caindo direto nele; ou **(b)** MVP **sem cobrança online**: pedido entra, pagamento é registrado manualmente / link de pagamento do próprio lojista. Recomendação: decidir (a) ou (b) explicitamente antes da etapa 3. |
| **D5** | O legado já opera assinatura automatizada com Stripe (trial → plano pago). Existe, portanto, experiência e código de referência para Billing em N1 se a opção (b) de D5 for escolhida; a recomendação (a) — cobrança manual no início — continua válida. |

## 3. Mapa: conceito do legado → capacidade da nova Foxy

Legenda: **Conceito** = vale a ideia, código não serve · **Copiar e adaptar** = peça de código candidata · **Não levar** = específico do nicho ou contrário à nova arquitetura.

| Legado | Nova Foxy | Veredito | Observação |
|---|---|---|---|
| `User` dono + `TeamMember` + `getWorkspaceId()` | Identity / Organization / Membership | **Conceito** | Novo modelo é centrado em Organization com Membership múltipla — estrutura diferente. Lição útil: isolamento por *global scope* + checagem no controller funcionou; no novo, o tenant vem do Execution Context. |
| `Plan` + capabilities + limites + `hasCapability` | Product Catalog / Plans / Entitlements | **Conceito** | Capabilities ≈ features; limites ≈ limits. Diferença crítica: no legado o código consulta o plano diretamente; no novo, produtos consultam **só Entitlements**. |
| `Subscription` + trial (`NewUserProvisioning::startTrial`) | Subscription | **Conceito** | Ciclo trial → ativo já validado com usuários reais. |
| `StripeBillingService` | Billing (adaptador de provedor) | **Copiar e adaptar** | Só se D5 = assinatura automatizada. |
| `Product`, `StockTransaction`, `Supplier` | Catalog / Inventory | **Conceito** | Estoque já é por movimentação (= regra "estoque só muda por StockMovement"). Falta **variante**, que no novo é a unidade estocável. |
| `Sale`, `SaleItem`, `SaleService` | Orders (pedido manual) | **Conceito + trechos** | `SaleItem` já guarda snapshot de nome e preço. Lógica de parcelado (centavos, sobra na última parcela) e desfazer venda devolvendo estoque são candidatas a copiar. Acoplamento direto com Financeiro **não** se leva (no novo seria evento). |
| `Coupon` | Checkout (cupom) | **Conceito** | Fora do MVP. |
| `Client` + importação (`SpreadsheetReader`, `ValueParser`) | Customers / importação | **Copiar e adaptar** | `ValueParser` (datas, telefones, valores BR) é genérico e testado. Importação em massa está fora do MVP. |
| `ActivityLog` | Audit | **Conceito** | Legado guarda só usuário/ação/descrição/IP; o novo exige actor + on_behalf_of + target + result + correlation_id. |
| S3 via Flysystem, "nenhum arquivo no disco" | Files | **Conceito** | Mesma regra vale no novo; somar `file_id` e URLs assinadas. |
| `WhatsAppService` (Evolution, uma instância por conta) | Notifications (canal WhatsApp) | **Copiar e adaptar (depois)** | MVP do Commerce é só e-mail. |
| Backoffice `/admin` | Backoffice Foxy (N1) | **Conceito** | Novo exige acesso de staff **auditado**. |
| `WorkspaceDataExport` (portabilidade LGPD) | LGPD / portabilidade | **Conceito** | Boa prática a manter desde o início. |
| Página pública em blocos (`PublicProfileBlock`) | Storefront | **Conceito** | Vitrine é outro domínio; ideia de blocos configuráveis pode inspirar o tema. |
| Deploy por script em EC2, Postgres na instância | Infra (etapa 4) | **Conceito** | Custo baixo já provado; ponto de partida para a etapa 4. |
| Anamnese, diário, turmas, agenda, orçamentos | — | **Não levar** | Domínio de serviços; fora do Commerce. |
| Financeiro do profissional | — | **Não levar agora** | Commerce MVP tem Insights, não financeiro completo. Reavaliar como módulo futuro. |

## 4. Regras de reaproveitamento

1. **Nada é compartilhado em tempo de execução**: sem pacote comum, sem banco comum, sem leitura do banco do legado.
2. Reaproveitar = **copiar a peça específica**, adaptar ao modelo novo (Organization, Execution Context) e trazer/escrever o teste junto.
3. Só copiar quando a peça resolver algo **do escopo aprovado** — não importar funcionalidades do legado "porque já existem".
4. O legado continua com manutenção própria; mudanças nele seguem as regras do repositório dele.
