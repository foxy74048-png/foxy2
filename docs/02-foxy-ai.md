# Foxy AI — Primeiro produto do ecossistema

> **Status:** Rascunho v0.1 — decisões pendentes na seção 11
> **Etapa:** 2 de 5 (Core conceitual ✔ → **Foxy AI** → Recorte da Platform → Arquitetura concreta → Implementação)
> **Decisão de ordem (2026-10-13):** a Foxy AI vem antes do Commerce, por ser útil a todos os produtos. O Commerce (`03-foxy-commerce.md`) passa a ser o segundo produto e continua em rascunho.

---

## 1. Por que começar pela IA

- Todo produto da Foxy pode usar IA.
- **Decisão (2026-10-13):** a IA é desenvolvida primeiro e por conta própria. Se o sistema atual vai usá-la, ou se o caminho é seguir só com a nova Foxy, fica para **depois** — não entra no desenho agora.
- Começar por ela obriga a construir **a fatia certa do Core**: Organization, Execution Context, credencial de API, medição de uso, limites e auditoria. Tudo isso será reaproveitado pelo Commerce.

**Risco que este documento evita:** uma IA "genérica" sem uso concreto vira uma Platform maior que os produtos (contexto, seção 9). Por isso a Foxy AI nasce com **uma capacidade concreta** (seção 3), validada por um console de testes da equipe Foxy até existir o primeiro sistema consumidor, e cresce só quando surgir outro uso.

## 2. Como a Foxy AI é "separada"

| Pergunta | Decisão |
|---|---|
| Quem consome? | Todos os sistemas, **somente pela API** da Foxy AI. Nenhum sistema fala direto com um provedor de modelo. |
| É um serviço/deploy separado? | **Não no início.** É um módulo do monolito modular da nova Foxy, junto do Core. Fica separada por contrato: tem a própria API e os próprios dados, e ninguém lê as tabelas dela. Ganha deploy próprio só se houver motivo real (contexto, seção 17). |
| E os produtos (Commerce, futuros)? | Usam a mesma capacidade pela interface do módulo (ou pela API, se forem apps separados). O contrato é o mesmo. |
| E sistemas externos? | Se houver, consomem a **API pública**, como qualquer cliente. Não ganham acesso a nada interno. |

```text
  Console de testes Foxy          Commerce (futuro)        Produto X (futuro)
            │ API                       │                         │
            └───────────────┬───────────┴─────────────────────────┘
                            ▼
                ┌────────── FOXY AI ──────────┐
                │  Tarefas (tasks) versionadas │
                │  Provedor encapsulado        │
                │  Medição de uso + limites    │
                │  Auditoria                   │
                └──────────────┬───────────────┘
                               ▼
                 Core mínimo: Organization, API Client,
                 Execution Context, Entitlements (uso), Audit
```

## 3. Primeira capacidade: escrita assistida

**Problema [H]:** profissionais e pequenos negócios perdem tempo escrevendo sempre os mesmos textos (lembretes, mensagens de aniversário, divulgação, descrição de serviços), e muitos não se sentem seguros escrevendo.

**Capacidade:** gerar sugestões de texto prontas para revisar, a partir de poucos dados estruturados.

**Por que essa:**
- útil para qualquer negócio pequeno e para o Commerce (descrição de produto = D7 do Commerce);
- **não precisa de dados sensíveis** (saúde, documentos, valores);
- a IA só **responde**: não executa ações, e uma pessoa sempre revisa antes de enviar;
- custo por uso baixo e previsível.

**Tarefas candidatas [H]** (escolher 1 a 3 para começar — ver A1):

| Tarefa | Consumidor | Entrada (exemplo) |
|---|---|---|
| `message.whatsapp` | Qualquer produto | ocasião (lembrete, aniversário, retorno, divulgação), nome do negócio, serviço, tom |
| `text.service_description` | Qualquer produto | nome do serviço, duração, público, tom |
| `text.public_page_bio` | Qualquer produto | segmento, especialidades, tom |
| `text.product_description` | Commerce (futuro) | nome, características, foto (futuro) |

## 4. Contrato: tarefas, não prompt livre

A API **não repassa prompts livres** ao modelo. O consumidor pede uma **tarefa** registrada, com campos definidos:

```text
POST /v1/tasks/message.whatsapp
{ "input": { "occasion": "aniversario", "business_name": "...", "tone": "carinhoso" },
  "variants": 2,
  "account_ref": "conta-123" }

→ { "suggestions": ["...", "..."], "task_version": 3, "usage": { ... }, "request_id": "..." }
```

**Por quê:**
- **Custo e abuso controlados:** ninguém usa a Foxy como proxy de IA genérico.
- **Qualidade:** o prompt fica com a Foxy, é versionado e pode ser melhorado sem mexer nos consumidores.
- **Segurança e LGPD:** campos definidos permitem bloquear o envio de dados que não deveriam sair.
- **Troca de provedor** sem quebrar ninguém.

Nomes de pessoas não precisam ir ao modelo: o consumidor recebe o texto com marcadores (`{nome}`) e preenche localmente.

## 5. Modelo de domínio (conceitual)

```text
Organization ──< ApiClient (credencial, escopos)
      │
      ├──< UsageRecord   (tarefa, tokens, custo estimado, account_ref, request_id)
      └──< AuditEntry    (quem, tarefa, resultado, correlation_id)

AiTask (global, versionada)
├── id               ex.: message.whatsapp
├── version
├── input_schema     campos aceitos e validação
├── prompt_template
├── model_profile    ex.: "rápido e barato"
└── limits           tamanho máximo de entrada/saída
```

- `AiTask` é **global** (definida pela Foxy); uso, auditoria e credenciais pertencem a uma Organization.
- `account_ref` é um identificador opaco do consumidor (ex.: a conta do cliente dentro daquele sistema), usado só para limitar e medir por conta — a Foxy AI não sabe quem é a pessoa.

## 6. Fluxo de uma chamada

```text
Consumidor chama a API com a credencial (ApiClient)
      ↓
Execution Context: principal = API Client, organization, account_ref, correlation_id, source = api
      ↓
Rate limit (por ApiClient)
      ↓
Entitlements: a organização tem a tarefa liberada? há saldo no mês (organização e account_ref)?
      ↓
Valida a entrada contra o input_schema da tarefa
      ↓
Monta o prompt (versão atual da tarefa) → provedor encapsulado
      ↓
Valida a saída (tamanho, formato)
      ↓
UsageRecord + AuditEntry → resposta com sugestões
```

## 7. Recorte do Core que a Foxy AI exige (insumo da etapa 3)

| Capacidade | Nível | Observação |
|---|---|---|
| **Organization** | N1 | Uma por consumidor. No início, só a da equipe Foxy (console de testes). |
| **API Clients / API pública** | **N1** | É o próprio produto: todos consomem a IA por API. Versionada (`/v1`). Exposição a clientes externos só quando houver demanda (Core, Q7). |
| **Execution Context** | N1 | Com `source = api` e `account_ref`. |
| **Entitlements** | N1 (só uso medido) | Medidor `ai.requests.monthly` (ou tokens) por organização e por `account_ref`. Sem planos. |
| **Audit** | N1 | Somente-inclusão. |
| **Identity / Membership / Authorization** | N0 | Sem tela de login no início: credenciais criadas pela equipe Foxy por comando. Entram de verdade com o Commerce. |
| **Plans / Subscription / Billing** | Fora | Quem cobra o cliente final é o consumidor (ex.: o plano de um produto inclui X usos/mês). |
| **Events / Files / Notifications** | Fora | Sem necessidade nesta capacidade. |
| **Backoffice** | Mínimo | Comando ou relatório simples de uso e custo por organização/conta. |

## 8. Fora do escopo agora

IA executando ações (tools), chat/assistente, RAG/base de conhecimento, embeddings, agentes e workflows, vários provedores ao mesmo tempo, imagens/áudio, fine-tuning, dados de saúde (anamnese, diário, exames).

Entram quando um produto tiver caso concreto (contexto, seções 20–21). O caminho previsto é **Responder → Analisar → Recomendar → Executar**; aqui estamos no primeiro passo.

## 9. Segurança e LGPD

- **Proibido enviar** dados sensíveis (saúde, anamnese, diário, documentos) nesta fase. O `input_schema` só aceita os campos da tarefa.
- Minimizar dados pessoais: usar marcadores em vez de nomes sempre que possível.
- Provedor escolhido deve garantir **não usar os dados para treino** e ter termos de tratamento compatíveis com a LGPD.
- Prompts e respostas **não são guardados por padrão**; `UsageRecord` e `AuditEntry` guardam metadados (tarefa, tamanho, custo, resultado). Guardar conteúdo para avaliar qualidade é decisão explícita (A6).
- Credenciais de API com hash, escopo por tarefa, rotação e revogação.
- Chave do provedor de IA só no servidor, fora do repositório.

## 10. Como validar sem um sistema consumidor

Até existir o primeiro sistema que use a IA, a validação é feita por um **console de testes interno** da equipe Foxy: uma tela simples que chama a mesma API, escolhe a tarefa, preenche os campos e mostra as sugestões, o uso e o custo. Não é produto para cliente.

Qual sistema será o primeiro consumidor (o atual, o Commerce ou outro) é decisão **posterior**, fora deste documento.

## 11. Decisões pendentes

| # | Decisão | Recomendação |
|---|---|---|
| **A1** | Quais tarefas começam? | `message.whatsapp` primeiro (maior frequência de uso); `text.service_description` em seguida. |
| **A2** | Quem paga o custo da IA e qual o limite? | A IA mede uso e custo e aplica limite mensal por organização (e por `account_ref`). Quem paga e quanto é decidido por cada produto consumidor, depois de medir o custo real no console. |
| **A3** | Como um sistema externo se encaixa (uma organização + `account_ref`, ou uma por conta)? | **Adiada** — só quando houver um sistema externo consumidor. |
| **A4** | Provedor de modelo | Etapa 4. Critérios: qualidade em português, custo, latência, política de não treino com os dados, termos LGPD. |
| **A5** | A sugestão sempre passa por revisão humana antes de enviar? | **Sim.** A IA nunca envia nada sozinha nesta fase. |
| **A6** | Guardar conteúdo de prompts/respostas para avaliar qualidade? | Não no início; se necessário, amostragem por tempo limitado e sem dados pessoais. |

## 12. Próxima etapa

Com A1–A6 respondidas, este documento vira v1.0 e passamos à **etapa 3 — Recorte da Platform** (a tabela da seção 7 vira escopo fechado), depois **etapa 4 — Arquitetura concreta**. Código só após aprovação da etapa 4.
