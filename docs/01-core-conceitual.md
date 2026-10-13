# Foxy Core — Arquitetura Conceitual

> **Status:** v0.1 — aprovado em 2026-10-06 (questões abertas da seção 12 aceitas conforme recomendação)
> **Etapa:** 1 de 5 (Core conceitual → Commerce → Recorte da Platform → Arquitetura concreta → Implementação)
> **Escopo:** responsabilidades, limites e relações. **Não** define stack, banco, infraestrutura nem implementação.

---

## 1. Objetivo deste documento

Definir **o que** o Core da Foxy é, **o que cada capacidade faz e não faz**, e **como as capacidades se relacionam entre si e com os produtos**.

Este documento existe para que o Foxy Commerce (e os produtos seguintes) seja projetado já encaixado no ecossistema, sem que a Platform seja construída antes de ser necessária.

> Definir o Core conceitualmente antes. Implementar apenas o Core necessário para o primeiro produto. Manter os boundaries preparados para evolução.

---

## 2. Visão geral

```text
┌──────────────────────────────────────────────────────────────────┐
│                         FOXY PRODUCTS                            │
│        Commerce        │      Produto X       │    Produto Y     │
└───────────┬──────────────────────┬───────────────────┬───────────┘
            │   (somente contratos públicos do Core)   │
┌───────────▼──────────────────────▼───────────────────▼───────────┐
│                           FOXY CORE                              │
│                                                                  │
│  ACESSO              COMERCIAL               CAPACIDADES          │
│  ─────────           ─────────────           ────────────         │
│  Identity            Product Catalog         Events               │
│  Organization        Plans                   Files                │
│  Membership          Subscription            Notifications        │
│  Authorization       Billing                 Audit                │
│                      Entitlements            API Gateway/Keys     │
│                                                                  │
│  ─────────────── Execution Context (transversal) ──────────────  │
│  ─────────────── Foxy AI (transversal) ────────────────────────  │
└──────────────────────────────────────────────────────────────────┘
```

O Core é dividido em três grupos:

| Grupo | Pergunta que responde |
|---|---|
| **Acesso** | Quem é você, em nome de qual organização está agindo e o que pode fazer? |
| **Comercial** | O que essa organização contratou e o que isso dá direito a usar? |
| **Capacidades** | Quais serviços técnicos compartilhados os produtos podem usar? |

E dois elementos transversais: **Execution Context** e **Foxy AI**.

---

## 3. Regras de dependência

Estas regras valem para todo o ecossistema e são o principal mecanismo contra acoplamento.

1. **Produtos dependem do Core. O Core nunca depende de produtos.**
   O Core não conhece "pedido", "produto de loja" ou "estoque". Produtos se registram no Core (permissões, features, tools de IA, tipos de evento), mas o Core não importa código de produto.

2. **Produtos não dependem uns dos outros diretamente.**
   Comunicação entre produtos apenas por API pública ou eventos.

3. **Todo acesso ao Core passa por contratos públicos.**
   Nenhum produto lê tabelas internas do Core, e nenhum módulo do Core lê tabelas de outro módulo do Core.

4. **Produtos consultam Entitlements — nunca Plans, Subscription ou Billing.**
   Um produto pergunta "esta organização pode usar X? Qual o limite de Y?". Ele não sabe qual plano o cliente tem, nem se pagou. Isso permite mudar planos e preços sem alterar código de produto.

5. **Dentro do Core, a direção das dependências é:**

```text
Identity ◄── Membership ──► Organization
                 ▲
           Authorization ──► Entitlements ◄── Subscription ──► Plans ──► Product Catalog
                                                   ▲
                                                Billing

Events, Files, Notifications, Audit: capacidades "folha" — usadas por todos, não dependem de nenhum módulo de negócio.
```

---

## 4. Execution Context (o conceito mais importante do Core)

Toda operação no ecossistema — uma requisição HTTP, um job, um consumidor de evento, uma ação da IA — acontece dentro de um **Execution Context** explícito:

```text
ExecutionContext
├── principal        quem está agindo (ver Principals abaixo)
├── organization     em nome de qual organização (tenant)
├── membership       vínculo do principal com a organização (papéis)
├── on_behalf_of     usuário responsável, quando o principal é delegado (IA, integração)
├── correlation_id   rastreio ponta a ponta (logs, eventos, auditoria)
└── source           web, api, job, event, ai
```

**Por que isso é central:**

- **Isolamento de tenant:** nenhuma operação de dados de negócio acontece sem `organization` definida. O tenant vem do contexto, nunca de um parâmetro arbitrário do cliente.
- **Autorização:** as checagens usam o contexto, não variáveis soltas.
- **Auditoria e eventos:** registram automaticamente quem, em nome de quem, de onde e em qual organização.
- **IA:** a IA executa ações com um contexto delegado, nunca com privilégios próprios ilimitados.

### Principals (quem pode agir)

| Tipo | Descrição | Exemplo |
|---|---|---|
| **User** | Pessoa autenticada | Lojista acessando o painel |
| **API Client** | Credencial de integração vinculada a uma organização | ERP do cliente chamando a API |
| **AI Agent** | Foxy AI agindo em nome de um usuário | "Crie uma promoção de 10%" |
| **System** | Processos internos da Foxy | Job de renovação de assinatura |

Regra: **um principal delegado (AI Agent, API Client) nunca tem mais permissões do que quem o delegou.**

---

## 5. Capacidades — Grupo Acesso

### 5.1 Identity

**Responsabilidade:** saber quem é a pessoa e provar isso.

| Faz | Não faz |
|---|---|
| Cadastro da pessoa (User) | Não sabe a qual organização o usuário pertence |
| Credenciais: senha, login social, magic link, MFA | Não decide o que o usuário pode fazer |
| Sessões e tokens | Não guarda dados de negócio do usuário |
| Recuperação de conta, verificação de e-mail/telefone | |

**Conceitos:**
- **User** — pessoa global, única no ecossistema. Um e-mail = um User, independentemente de quantas organizações use.
- **Credential / Identity Provider** — formas de autenticação vinculadas a um User (um User pode ter senha + Google).
- **Session** — autenticação ativa.

**Decisão conceitual importante:**
> **User do Core ≠ cliente final de um produto.**
> O comprador de uma loja no Commerce **não** é um User da Foxy. Ele é um `Customer`, entidade do domínio do Commerce, pertencente à organização do lojista. Users são pessoas que *operam* organizações dentro da Foxy.
> Se algum dia o comprador precisar de login na loja, isso é uma identidade de cliente do produto, separada da identidade de operador. (Ver questão aberta Q2.)

### 5.2 Organization

**Responsabilidade:** representar o tenant — a empresa, negócio ou operação que usa a Foxy.

| Faz | Não faz |
|---|---|
| Cadastro da organização (nome, documento, dados fiscais básicos) | Não contém dados de domínio dos produtos |
| Ciclo de vida: ativa, suspensa, encerrada | Não decide o que a organização contratou |
| Identificador de tenant usado por todo o ecossistema | |
| Configurações globais (fuso, moeda, idioma) | |

**Regra:** todo dado de negócio de qualquer produto pertence a exatamente uma Organization (`organization_id`). Dados globais (ex.: lista de países, catálogo de produtos Foxy) são explicitamente marcados como globais.

### 5.3 Membership

**Responsabilidade:** o vínculo entre User e Organization.

| Faz | Não faz |
|---|---|
| Quem pertence a qual organização | Não define o que cada papel significa (isso é Authorization) |
| Convites, aceite, remoção | |
| Papéis atribuídos ao membro | |
| Status do vínculo (ativo, convidado, suspenso) | |

```text
User ──< Membership >── Organization
            │
            └── roles[]
```

Um User pode ter várias Memberships. A sessão do usuário escolhe uma organização ativa, que vira o `organization` do Execution Context.

### 5.4 Authorization

**Responsabilidade:** responder "este principal pode executar esta ação nesta organização?".

A decisão de acesso tem **três camadas**, e cada uma pertence a um lugar diferente:

```text
1. A ORGANIZAÇÃO tem direito?      → Entitlements (Core)       "commerce.marketplace habilitado?"
2. O MEMBRO tem permissão?         → Authorization (Core)      "papel permite commerce.order.cancel?"
3. A REGRA DE NEGÓCIO permite?     → Produto                   "pedido já enviado não pode ser cancelado"
```

**Modelo proposto: RBAC com permissões namespaced registradas pelos produtos.**

- **Permission** — string estável com namespace do produto: `commerce.order.read`, `commerce.order.cancel`, `core.members.invite`.
- **Catálogo de permissões** — cada produto *declara* suas permissões ao Core. O Core armazena e avalia, mas não interpreta o significado.
- **Role** — conjunto de permissões. Papéis padrão do sistema (Owner, Admin, Member) + papéis padrão por produto. Papéis customizados por organização ficam para o futuro (não implementar antes de existir demanda).
- **Owner** — toda organização tem ao menos um Owner, que não pode ser removido sem transferência.

| Faz | Não faz |
|---|---|
| Catálogo de permissões e papéis | Não conhece regras de negócio dos produtos |
| Avaliação: principal + organização + permissão → sim/não | Não verifica se o recurso específico está num estado válido |
| Regras de delegação (IA, API Clients) | |

---

## 6. Capacidades — Grupo Comercial

Este grupo separa quatro coisas que costumam ser misturadas:

```text
O QUE A FOXY VENDE     COMO ESTÁ EMPACOTADO     O QUE O CLIENTE CONTRATOU     O QUE ISSO DÁ DIREITO
Product Catalog   ──►  Plans               ──►  Subscription            ──►  Entitlements
                                                     │
                                                     ▼
                                                  Billing  (cobrança e pagamento)
```

### 6.1 Product Catalog

**Responsabilidade:** lista dos produtos Foxy existentes e suas features.

- **Product** — `commerce`, futuros produtos. Metadados (nome, status), não código.
- **Feature** — capacidade identificável de um produto: `commerce.storefront`, `commerce.marketplace_sync`, `ai.assistant`.
- **Limit / Meter** — algo mensurável: `commerce.products.max`, `ai.tokens.monthly`.

É o vocabulário comum entre Plans e Entitlements.

### 6.2 Plans

**Responsabilidade:** empacotamento comercial.

- **Plan** — conjunto de features e limites com preço. Ex.: "Commerce Starter", "Commerce Pro".
- **Add-on** — complemento contratável separadamente (ex.: pacote extra de IA).
- **Versionamento** — mudar um plano cria uma nova versão; clientes antigos permanecem na versão contratada até migração explícita (*grandfathering*).

| Faz | Não faz |
|---|---|
| Define o que cada plano contém e quanto custa | Não sabe quem contratou |
| Versionamento de planos | Não é consultado diretamente por produtos |

### 6.3 Subscription

**Responsabilidade:** o contrato de uma organização com um plano.

- Ciclo de vida: `trialing → active → past_due → suspended → canceled`.
- Upgrade, downgrade, cancelamento, renovação.
- Mudanças de estado geram eventos (`SubscriptionActivated`, `SubscriptionPastDue`, `SubscriptionCanceled`) que recalculam Entitlements.

### 6.4 Billing

**Responsabilidade:** cobrar a organização pelo que ela usa da Foxy.

- Faturas, cobranças, métodos de pagamento, recibos, notas fiscais (futuro).
- Integração com provedor de pagamento encapsulada (o domínio não conhece o provedor).
- Resultado de pagamentos alimenta Subscription (`PaymentSucceeded`, `PaymentFailed`).

**Decisão conceitual importante:**
> **Billing da Foxy ≠ Pagamentos do Commerce.**
> - **Billing (Core):** o lojista pagando a Foxy pela assinatura.
> - **Payments (Commerce):** o comprador pagando o lojista por um pedido.
> São domínios diferentes, com donos de dinheiro diferentes, regras diferentes e riscos diferentes. Podem eventualmente compartilhar um *adaptador técnico* de provedor de pagamento, mas nunca o mesmo modelo de domínio.

### 6.5 Entitlements

**Responsabilidade:** responder **o que uma organização tem direito de usar agora**. É o único ponto do grupo comercial que os produtos consultam.

```text
Entitlements efetivos da organização =
      features e limites do plano da Subscription ativa
    + add-ons contratados
    + concessões manuais (trial estendido, cortesia, suporte)
    − restrições por estado (ex.: past_due → modo somente leitura)
```

**Interface conceitual para produtos:**
- `has_feature(org, "commerce.marketplace_sync")` → sim/não
- `get_limit(org, "commerce.products.max")` → número ou ilimitado
- `check_usage(org, meter)` / `record_usage(org, meter, quantidade)` → para limites medidos (ex.: tokens de IA)

| Faz | Não faz |
|---|---|
| Calcula direitos efetivos | Não sabe preços |
| Controla limites e uso medido | Não executa cobrança |
| Concessões manuais | Não aplica regras de negócio do produto |

---

## 7. Capacidades — Grupo Técnico

### 7.1 APIs

**Responsabilidade:** definir como sistemas externos e internos acessam as capacidades da Foxy.

Três níveis de contrato, com regras diferentes:

| Nível | Quem usa | Regras |
|---|---|---|
| **Interno entre módulos** | Módulos dentro do monolith | Interface pública do módulo; nunca acessar tabelas de outro módulo |
| **API de aplicação** | Frontends da própria Foxy | Pode evoluir junto com o frontend |
| **API pública** | Clientes e parceiros | Versionada, documentada, estável, autenticada por API Client, com rate limit |

Capacidades do Core relacionadas:
- **API Clients / API Keys** — credenciais por organização, com escopos (subconjunto de permissões).
- **Webhooks de saída** — produtos publicam eventos de integração; o Core entrega para URLs configuradas pelo cliente (futuro, quando houver demanda).
- **Rate limiting** — por organização e por API Client.

### 7.2 Events

**Responsabilidade:** comunicar que algo aconteceu, desacoplando quem produz de quem reage.

**Dois tipos de evento:**

| Tipo | Alcance | Exemplo | Estabilidade |
|---|---|---|---|
| **Evento de domínio** | Dentro de um módulo/produto | `OrderLineAdded` | Pode mudar livremente |
| **Evento de integração** | Entre módulos, produtos e para fora | `OrderPaid` | Contrato versionado |

**Envelope padrão (conceitual)** para eventos de integração:

```text
event_id, type, version, occurred_at,
organization_id, actor (principal + on_behalf_of), correlation_id,
payload
```

**Regras:**
- Evento é fato passado (`OrderPaid`, não `PayOrder`).
- Evento sempre carrega `organization_id`; consumidores processam no contexto dessa organização.
- Consumidores devem ser idempotentes.
- Publicação e mudança de estado devem ser consistentes (ex.: *outbox* — decisão de implementação na etapa 4).

### 7.3 Files

**Responsabilidade:** armazenar e servir arquivos com controle de acesso.

- Todo arquivo pertence a uma organização e tem um dono lógico (`commerce.product_image`, por exemplo).
- Produtos guardam apenas a **referência** ao arquivo (`file_id`), não caminhos de storage.
- Acesso via URLs temporárias assinadas ou URLs públicas explicitamente marcadas (ex.: imagens de vitrine).
- Provedor de storage encapsulado.
- Limites de armazenamento podem vir de Entitlements.

### 7.4 Notifications

**Responsabilidade:** entregar mensagens a pessoas pelos canais adequados.

- Canais: e-mail, WhatsApp, SMS, push, in-app (adicionados conforme necessidade).
- Templates, preferências do destinatário, histórico de envio, reenvio em falha.
- Provedores encapsulados.

**Limite claro:** Notifications **não decide quando notificar**. O produto decide ("pedido pago → avisar o lojista e o comprador") e pede a entrega. Notifications só sabe *como* entregar.

**Destinatários** podem ser Users do Core ou contatos externos fornecidos pelo produto (ex.: e-mail do comprador). Para estes, o produto é o responsável pela base legal do contato (LGPD).

### 7.5 Audit

**Responsabilidade:** registrar de forma confiável **quem fez o quê, quando, em qual organização e por qual meio**.

```text
AuditEntry
├── occurred_at
├── organization_id
├── actor (principal, on_behalf_of)    ← inclui ações da IA
├── action                             ex.: commerce.order.cancel
├── target (tipo + id)
├── result (sucesso / negado / erro)
├── source / correlation_id
└── metadata (sem dados pessoais desnecessários)
```

**Audit ≠ Logs ≠ Events:**

| | Propósito | Público | Retenção |
|---|---|---|---|
| **Logs** | Diagnóstico técnico | Engenharia | Curta |
| **Events** | Integração e reação entre componentes | Sistemas | Conforme necessidade |
| **Audit** | Responsabilização e conformidade | Clientes, suporte, LGPD | Longa, somente-inclusão |

Um mesmo acontecimento pode gerar os três, mas eles não se substituem.

---

## 8. Foxy AI — capacidade transversal

**Responsabilidade:** permitir que produtos ofereçam IA (responder, analisar, recomendar, executar) com segurança, controle de custo e sem acoplamento a um provedor.

### 8.1 Divisão de responsabilidades

| Core (Foxy AI) | Produto |
|---|---|
| Acesso a modelos (provedores encapsulados) | Define **tools** do seu domínio |
| Execução segura de tools | Implementa a regra de negócio por trás de cada tool |
| Contexto delegado (AI Agent em nome de User) | Define o contexto/conhecimento relevante |
| Medição de uso e custo → Entitlements | Define a experiência (assistente, sugestão, automação) |
| Auditoria das ações da IA | |

### 8.2 Fluxo de execução

```text
Usuário pede algo
      ↓
Foxy AI interpreta e escolhe uma Tool (registrada pelo produto)
      ↓
Execution Context delegado: principal = AI Agent, on_behalf_of = User
      ↓
Entitlements: a organização pode usar IA / essa feature? há saldo?
      ↓
Authorization: o USER tem a permissão exigida pela tool?
      ↓
Confirmação humana (para ações sensíveis ou irreversíveis)
      ↓
Tool chama o MESMO serviço de aplicação que a interface usa
      ↓
Regra de negócio do produto
      ↓
Audit (registrado como ação da IA em nome do usuário) + uso medido
```

**Regras:**
- A IA nunca acessa banco ou tabelas diretamente; apenas tools.
- Uma tool executa o mesmo caminho de código que a ação manual equivalente — sem atalhos.
- A IA nunca tem mais permissão que o usuário que a acionou.
- Ações sensíveis exigem confirmação explícita. Cada tool declara seu nível de risco.
- Dados enviados a provedores de IA respeitam a LGPD (mínimo necessário, sem dados de outras organizações).

### 8.3 Evolução

Começa com o necessário (provavelmente: um provedor encapsulado + algumas tools + medição de uso). RAG, embeddings, workflows, avaliações e roteamento multi-modelo entram quando houver caso de uso concreto.

---

## 9. Como um produto se conecta ao Core

Um produto se integra ao ecossistema **declarando** e **consumindo**:

**Declara ao Core:**
- seu identificador no Product Catalog (`commerce`);
- suas features e limites;
- suas permissões e papéis padrão;
- seus eventos de integração públicos;
- suas tools de IA;
- seus tipos de arquivo e templates de notificação.

**Consome do Core:**
- Execution Context (quem, qual organização);
- Authorization (`can(...)`);
- Entitlements (`has_feature`, `get_limit`, `record_usage`);
- Events (publicar / assinar);
- Files, Notifications, Audit, Foxy AI.

**Nunca:**
- lê tabelas do Core ou de outro produto;
- consulta Plans, Subscription ou Billing para decidir acesso;
- define sua própria noção de usuário operador ou organização;
- implementa sua própria autenticação.

---

## 10. Exemplo de ponta a ponta (ilustrativo)

> Ana é dona da "Loja Bela". Ela convida Bruno como atendente e depois cancela um pedido.

1. **Identity:** Ana faz login → User `ana`.
2. **Membership:** Ana é Owner da Organization `loja-bela`. A sessão define `loja-bela` como organização ativa.
3. **Membership:** Ana convida Bruno com papel `commerce.attendant` (requer `core.members.invite`).
4. Bruno acessa e tenta cancelar um pedido:
   - **Entitlements:** `loja-bela` tem `commerce`? Sim (Subscription ativa, plano Starter).
   - **Authorization:** `commerce.attendant` tem `commerce.order.cancel`? Não → negado e registrado em **Audit**.
5. Ana pede à Foxy AI: "cancele o pedido 1042, cliente desistiu".
   - Contexto delegado: AI Agent em nome de Ana.
   - Ana tem a permissão → confirmação solicitada → Ana confirma.
   - **Commerce** aplica a regra: pedido ainda não enviado → cancelado.
   - **Events:** `OrderCanceled` publicado com `organization_id = loja-bela`.
   - **Notifications:** Commerce reage e pede envio de e-mail ao comprador.
   - **Audit:** "AI Agent, em nome de Ana, cancelou o pedido 1042".

Nenhum desses passos exigiu que o Commerce conhecesse planos, preços, provedores de IA ou de e-mail.

---

## 11. Níveis de maturidade (insumo para a etapa 3)

Para cada capacidade, o recorte da Platform (etapa 3) escolherá um nível. Os boundaries existem desde o nível 0; o que muda é a implementação.

| Nível | Significado |
|---|---|
| **N0 — Contrato** | Interface definida; implementação trivial ou fixa (ex.: Entitlements retorna "tudo liberado" para um plano único) |
| **N1 — Mínimo** | Implementação real e simples, suficiente para o primeiro produto |
| **N2 — Completo** | Recursos avançados, quando houver demanda real |

Exemplo da ideia (decisão real só na etapa 3): Entitlements pode nascer em **N0/N1** — o Commerce já chama `has_feature(...)`, mas por trás existe apenas um plano. Quando surgirem planos diferentes, o Commerce não muda.

Isso é o que permite **definir o boundary agora sem construir a capacidade completa agora**.

---

## 12. Questões abertas para decisão

| # | Questão | Recomendação inicial |
|---|---|---|
| Q1 | Um User pode criar várias organizações livremente? | Sim. Facilita quem tem mais de um negócio e é o modelo natural do multi-tenancy. |
| Q2 | Compradores das lojas terão login (área do cliente)? | Tratar como identidade do produto (Commerce), separada dos Users operadores. Decidir na etapa 2. |
| Q3 | Papéis customizados por organização no início? | Não. Apenas papéis padrão; customização quando houver demanda. |
| Q4 | Billing e Subscription: construir ou usar um provedor que já faça recorrência? | Usar recorrência do provedor encapsulada, mantendo Subscription e Entitlements como modelos próprios. Decidir na etapa 4. |
| Q5 | Agências/contadores que gerenciam várias organizações de clientes? | Já é suportado por Membership múltipla. Uma hierarquia de organizações fica fora do escopo até existir demanda. |
| Q6 | Admin interno da Foxy (suporte, backoffice)? | Principal `System`/staff separado, com acesso auditado a organizações. Desenho mínimo na etapa 3. |
| Q7 | API pública desde o início? | Boundary preparado (API Clients no modelo), exposição pública só quando houver cliente pedindo. |

---

## 13. Próxima etapa

**Etapa 2 — Foxy Commerce:** definir problema, público, MVP e domínio do Commerce. Em seguida, mapear quais capacidades deste documento ele usa e em qual nível de maturidade (insumo da etapa 3).
