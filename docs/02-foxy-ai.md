# Foxy AI — Primeiro produto do ecossistema

> **Status:** Rascunho v0.2 — decisões pendentes na seção 12
> **Etapa:** 2 de 5 (Core conceitual ✔ → **Foxy AI** → Recorte da Platform → Arquitetura concreta → Implementação)
> **Decisão de ordem (2026-10-13):** a Foxy AI vem antes do Commerce, por ser útil a todos os produtos.
> **Mudança de direção (2026-10-28):** a primeira capacidade deixa de ser "escrita assistida" e passa a ser o **assistente do sistema** (seção 3). A escrita assistida da v0.1 vira segunda capacidade (seção 10).

---

## 1. O que a Foxy AI faz

Uma IA que é **colocada dentro de um sistema** (o "sistema anfitrião"), entende esse sistema e ajuda quem o usa:

| Uso | Exemplo de pergunta | De onde vem a resposta |
|---|---|---|
| **Suporte de uso** | "Como eu remarco um atendimento?" | Conhecimento do sistema (manual, telas, glossário) |
| **Ajuda com erros** | "Apareceu 'horário indisponível', o que eu faço?" | Conhecimento + contexto do erro enviado pelo sistema |
| **Perguntas sobre os dados** | "Quem mais me deve este mês?" | **Consultas (tools)** que o próprio sistema oferece |

A mesma Foxy AI serve qualquer sistema: o que muda de um para outro é o **perfil do anfitrião** (seção 4). Ela não é feita para um sistema específico.

**Por que essa capacidade primeiro:**
- é útil para qualquer produto (todo sistema tem dúvidas de uso, erros e dados);
- reduz o suporte manual da Foxy;
- é o caminho **Responder → Analisar** do contexto (seção 20) — sem ainda **Executar**;
- obriga a construir a fatia certa do Core: Organization, Execution Context delegado, tools com permissão, medição de uso e auditoria.

## 2. Como a Foxy AI é "separada"

| Pergunta | Decisão |
|---|---|
| Quem consome? | Todos os sistemas, **somente pela API** da Foxy AI. Nenhum sistema fala direto com um provedor de modelo. |
| É um serviço/deploy separado? | **Não no início.** É um módulo do monolito modular da nova Foxy, separado por contrato (API e dados próprios). |
| A Foxy AI lê o banco do anfitrião? | **Nunca.** Ela só enxerga o que o anfitrião entrega: conhecimento e resultados de tools. |
| Quem garante as permissões? | **O anfitrião.** Ele executa as tools com as permissões do usuário logado. A IA nunca tem mais acesso do que esse usuário. |

```text
         Sistema anfitrião (ex.: console de testes, Commerce, outro)
   ┌──────────────────────────────────────────────────────────────┐
   │  Usuário logado ── janela do assistente                      │
   │  Executa as tools (consultas) com as permissões do usuário   │
   └──────────────┬───────────────────────────────▲───────────────┘
                  │ API: mensagem + contexto       │ pedido de tool / resposta
                  ▼                                │
   ┌──────────────────────── FOXY AI ─────────────┴───────────────┐
   │  Perfil do anfitrião (conhecimento + catálogo de tools)      │
   │  Conversa · Provedor de modelo encapsulado                   │
   │  Medição de uso + limites · Auditoria                        │
   └──────────────────────────────┬───────────────────────────────┘
                                  ▼
          Core mínimo: Organization, API Client, Execution Context,
                       Entitlements (uso), Audit
```

## 3. Os três usos em detalhe

### 3.1 Suporte de uso

- O anfitrião entrega um **pacote de conhecimento**: manual, descrição das telas, glossário, perguntas frequentes (texto/markdown).
- A cada mensagem, o anfitrião pode mandar o **contexto da tela** (ex.: "Agenda › Novo atendimento"), para a resposta ser específica.
- Se a resposta não está no conhecimento, a IA **diz que não sabe** e oferece falar com o suporte humano — não inventa caminho de tela.

### 3.2 Ajuda com erros

- O anfitrião envia o **contexto do erro**: código/mensagem, tela, ação que o usuário tentava fazer. Sem stack trace, sem dados pessoais.
- A IA explica em linguagem simples e sugere o próximo passo.
- Se parecer defeito do sistema (e não uso), a IA monta um **resumo para o suporte** e devolve um sinal de `escalate`; quem abre o chamado é o anfitrião (ver B6).

### 3.3 Perguntas sobre os dados

- O anfitrião declara **tools de consulta** no seu perfil, por exemplo:

  | Tool | Entrada | Devolve |
  |---|---|---|
  | `financeiro.saldos_em_aberto` | período, ordenação, limite | clientes com valor em aberto |
  | `agenda.atendimentos` | período, status | lista resumida de atendimentos |
  | `clientes.buscar` | termo | clientes encontrados |

- "Quem mais me deve este mês?" → a IA pede `financeiro.saldos_em_aberto(período = mês atual, ordenação = maior valor, limite = 5)` → o anfitrião executa **com as permissões do usuário** → a IA responde em texto.
- **Nesta fase, só tools de leitura.** Nenhuma tool cria, altera, apaga ou envia nada (ver seção 9).
- O catálogo enviado em cada conversa já vem **filtrado pelo anfitrião**: o usuário sem acesso ao Financeiro nem tem a tool `financeiro.*` disponível. E o anfitrião checa de novo ao executar.

## 4. Perfil do anfitrião (como a IA "entende" um sistema)

Cada sistema que recebe a Foxy AI tem um **perfil**, mantido pelo próprio anfitrião via API:

```text
HostProfile (pertence a uma Organization)
├── nome e descrição do sistema
├── KnowledgePack (versionado)   manual, telas, glossário, FAQ
├── ToolCatalog (versionado)     nome, descrição, schema de entrada,
│                                permissão exigida, nível de risco (só "leitura" nesta fase)
└── instruções de tom            ex.: "trate por você", "respostas curtas"
```

Trocar de sistema = trocar de perfil. O comportamento da IA (prompt de sistema, regras de segurança) fica com a Foxy e é versionado; o anfitrião só fornece **conhecimento e tools**, não prompt livre.

## 5. Contrato da API (esboço)

A IA **não executa** as tools: ela pede, o anfitrião executa e devolve o resultado (ver B2).

```text
POST /v1/conversations
{ "host_profile": "agenda-demo", "user_ref": "u-123",
  "allowed_tools": ["financeiro.saldos_em_aberto", "agenda.atendimentos"] }
→ { "conversation_id": "c-1" }

POST /v1/conversations/c-1/messages
{ "text": "Quem mais me deve este mês?",
  "page": "Financeiro › Recebimentos",
  "error": null }
→ { "status": "tool_requested",
    "tool_calls": [{ "id": "t-1", "name": "financeiro.saldos_em_aberto",
                     "input": { "period": "2026-10", "order": "amount_desc", "limit": 5 } }] }

POST /v1/conversations/c-1/tool-results
{ "results": [{ "id": "t-1", "output": [ { "cliente": "Ana", "em_aberto": 320.00 }, ... ] }] }
→ { "status": "answered",
    "reply": "Este mês quem mais deve é a Ana (R$ 320,00), seguida de...",
    "escalate": null, "usage": { ... }, "request_id": "..." }
```

- `user_ref` é um identificador opaco: a Foxy AI não sabe quem é a pessoa, só limita e mede por ele.
- A IA pode pedir mais de uma tool por rodada; há um limite de rodadas por mensagem.

## 6. Fluxo de uma mensagem

```text
Anfitrião envia a mensagem (credencial do API Client)
      ↓
Execution Context: principal = AI Agent, organization, on_behalf_of = user_ref,
                   correlation_id, source = api
      ↓
Rate limit (API Client e user_ref) → Entitlements: há saldo no mês?
      ↓
Monta o contexto: regras Foxy + perfil do anfitrião + tela/erro + histórico curto
      ↓
Provedor de modelo ──► resposta pronta  ou  pedido de tool (só do catálogo permitido)
      ↓                                         ↓
      │                    Anfitrião checa permissão, executa, devolve o resultado
      │                                         ↓
      └──────────────── nova rodada até a resposta final (com limite)
      ↓
UsageRecord + AuditEntry (cada tool pedida fica registrada) → resposta
```

## 7. Modelo de domínio (conceitual)

```text
Organization ──< ApiClient (credencial, escopos)
      ├──< HostProfile ──< KnowledgePack (versão) / ToolCatalog (versão)
      ├──< Conversation ──< Message (retenção curta — ver B5)
      ├──< UsageRecord   (conversa, tokens, custo estimado, user_ref, request_id)
      └──< AuditEntry    (user_ref, tool pedida, entrada, resultado ok/erro, correlation_id)
```

## 8. Recorte do Core que a Foxy AI exige (insumo da etapa 3)

| Capacidade | Nível | Observação |
|---|---|---|
| **Organization** | N1 | Uma por anfitrião/consumidor. No início, só a da equipe Foxy. |
| **API Clients / API pública** | N1 | É o próprio produto. Versionada (`/v1`). |
| **Execution Context** | N1 | Principal `AI Agent`, `on_behalf_of = user_ref`, `source = api`. |
| **Entitlements** | N1 (só uso medido) | Limite mensal por organização e por `user_ref`. Sem planos. |
| **Audit** | N1 | Somente-inclusão; registra cada tool pedida. |
| **Identity / Membership / Authorization** | N0 | As permissões do usuário final são do anfitrião. Credenciais da Foxy AI criadas pela equipe por comando. |
| **Plans / Billing / Events / Files / Notifications** | Fora | Sem necessidade nesta capacidade. |
| **Backoffice** | Mínimo | Relatório de uso e custo por organização e anfitrião. |

## 9. Fora do escopo agora

- **Tools que alteram dados** ou enviam algo (criar cobrança, remarcar, mandar mensagem). Entram depois, com confirmação humana — o passo **Executar**.
- Embeddings/RAG com banco vetorial (ver B3), vários provedores ao mesmo tempo, voz/imagem, fine-tuning, agentes autônomos e workflows.
- **Dados de saúde** (anamnese, diário, exames): nenhuma tool pode devolvê-los nesta fase.

## 10. Segunda capacidade: escrita assistida (da v0.1)

Sugestões de texto (`message.whatsapp`, `text.service_description`...) continuam previstas, como **tarefas** com campos definidos e marcadores no lugar de nomes. Entram depois do assistente, reaproveitando a mesma base (provedor, uso, auditoria).

## 11. Segurança e LGPD

- **Dados pessoais passam pelo modelo** quando a pergunta envolve dados (ex.: nome do cliente e valor em aberto). Por isso:
  - tools devolvem **só os campos necessários** (nome e valor — nada de CPF, telefone, endereço);
  - o provedor deve garantir **não usar os dados para treino** e ter termos de tratamento compatíveis com a LGPD;
  - resultados de tools **não são guardados** fora da conversa, e a conversa tem retenção curta (B5).
- **Prompt injection:** conhecimento e resultados de tools são tratados como **dado, não instrução**. Como nesta fase só há leitura, o pior caso é uma resposta errada — nunca uma ação.
- A IA só pede tools do catálogo permitido; o anfitrião checa de novo ao executar.
- Nunca mistura organizações: conversa, perfil e uso são sempre da organização da credencial.
- Credenciais de API com hash, escopo, rotação e revogação. Chave do provedor só no servidor, fora do repositório.

## 12. Como validar sem um sistema real

Um **console de testes interno** com um **anfitrião de demonstração**: um negócio fictício com clientes, atendimentos e pagamentos inventados, um manual curto e as tools de consulta implementadas sobre esses dados de mentira. O console chama a mesma API que um sistema real chamaria e mostra resposta, tools pedidas, uso e custo.

Assim a Foxy AI é provada de ponta a ponta sem depender de nenhum sistema existente. Qual será o primeiro anfitrião real é decisão posterior (B7).

## 13. Decisões pendentes

| # | Decisão | Recomendação |
|---|---|---|
| **B1** | Os três usos entram juntos? | **Sim, na ordem** suporte de uso → erros → perguntas sobre dados (só leitura). Os dois primeiros não precisam de tools e validam a base rápido. |
| **B2** | Quem executa as tools? | **O anfitrião**, no fluxo pede → executa → devolve (seção 5). A Foxy AI não guarda credenciais do anfitrião nem precisa alcançar a rede dele, e a permissão fica com quem já a conhece. Alternativa descartada por ora: a Foxy AI chamar um endpoint do anfitrião. |
| **B3** | Como a IA usa o conhecimento? | **Pacote inteiro no contexto**, com cache do provedor, enquanto o manual for pequeno. Busca por embeddings só quando o pacote não couber ou ficar caro. |
| **B4** | Provedor de modelo | Etapa 4. Critérios: qualidade em português, uso de tools confiável, custo, latência, política de não treino, termos LGPD. |
| **B5** | Guardar conversas? | Sim, **por 30 dias**, para continuidade e para melhorar o conhecimento; apagáveis por `user_ref`. Nunca usadas para treino. |
| **B6** | Para onde vai o "falar com o suporte"? | A Foxy AI só devolve `escalate` com um resumo; o **anfitrião** decide o canal (WhatsApp, e-mail, chamado). |
| **B7** | Primeiro anfitrião real | **Depois** da validação no console. Candidatos: o sistema atual (consumindo a API, como melhoria dele) ou o Commerce. |
| **B8** | Limite de uso | A IA mede uso e custo e aplica limite mensal por organização e `user_ref`; quem paga e quanto é decidido por cada anfitrião, depois de medir o custo real no console. |

Decisões da v0.1 que continuam valendo: A3 (sistema externo — adiada) e A5 (a IA nunca envia nada sozinha). A1, A2, A4 e A6 foram substituídas por B1, B8, B4 e B5.

## 14. Próxima etapa

Com B1–B8 respondidas, este documento vira v1.0 e passamos à **etapa 3 — Recorte da Platform** (a tabela da seção 8 vira escopo fechado), depois **etapa 4 — Arquitetura concreta**. Código só após aprovação da etapa 4.
