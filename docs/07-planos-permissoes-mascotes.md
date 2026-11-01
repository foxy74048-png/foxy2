# Foxy AI — Planos, permissões, mascotes e o assistente nos sistemas

> **Status:** v1.0 — E1–E8 aprovadas em 2026-11-01, com ajustes do responsável (planos em seis tamanhos + créditos extras de 1.000 em 1.000; raposas Chico, Lud, Lobinho e Ayla, renomeáveis; layout no Core a partir do visual da Foxy atual). Implementação em andamento (seção 8).
> **Pedido (2026-11-01):** planos e permissões bem definidos e integrados por API (mesmo entre sistemas nossos), preparados para escalar e para modelos mais caros; a IA representada por uma **raposa** que a pessoa escolhe ("a sua IA"); layout profissional, unissex e simples; IA que lê o sistema, ajuda a usar e sugere mensagens.
> **Regra mantida:** nada de dados fictícios em contas reais; a clínica de demonstração continua só no console de testes.

---

## 1. Quem é quem (a mudança principal)

Hoje cada **sistema** é uma organização. Para ter plano, limite e mascote **por cliente**, a hierarquia passa a ser:

```text
Foxy (nós)
 └── Integração ............ um sistema nosso (ex.: Foxy Agenda, Commerce). Tem o manual e o catálogo de consultas.
      └── Organização ....... um cliente desse sistema (ex.: a Clínica Bem Viver). Tem plano, créditos, mascote, conversas.
           └── Usuário ...... uma pessoa do cliente (user_ref). Tem permissões e pode ter a sua raposa.
```

- **Integração** (novo no Core): credencial "de sistema" que pode criar organizações para os seus clientes e agir em nome delas — sempre uma por requisição, informada no cabeçalho `X-Foxy-Organization`. Nunca vê dados de outra integração.
- O **manual e as consultas** (perfil do anfitrião) passam a pertencer à **integração**: são iguais para todos os clientes daquele sistema. Hoje pertencem à organização — muda com migração aditiva.
- **Organização = cliente final**, como o Core sempre previu ("Organization é a unidade central"). Isolamento, limite, uso e auditoria continuam por organização.
- Resolve a decisão A3, que estava adiada.

## 2. Planos

### 2.1 Créditos Foxy (a unidade de consumo)

Contar "mensagens" quebra quando entram modelos mais caros (uma mensagem no Opus custa ~30× uma no Haiku). Proposta: o plano dá **créditos por mês**, e cada resposta consome créditos **proporcionais ao custo real**.

- **1 crédito = US$ 0,001 de custo de IA** (estimado pelo `usage_records`, que já existe).
- Haiku: ~0,5 crédito por mensagem · Sonnet: ~9 · Opus: ~16 (estimativas atuais).
- Modelo novo e mais caro → consome mais créditos **sozinho**, sem mudar plano nem código.
- O usuário vê em linguagem simples: "Você usou 35% da franquia deste mês".

### 2.2 Faixas de modelo (o plano nunca cita o nome do modelo)

| Faixa | Hoje aponta para | Para quê |
|---|---|---|
| `rapida` | Claude Haiku 5.5 | Dúvidas de uso, erros, consultas simples |
| `equilibrada` | Claude Sonnet 5.5 | Análises e textos mais elaborados |
| `avancada` | Claude Opus 5.5 | Casos difíceis (futuro: análises e recomendações) |

A ligação faixa → modelo fica na configuração da Foxy. Quando sair um modelo melhor, troca-se a ligação e todos os planos ganham sem migração.

### 2.3 O que um plano define

```text
AiPlan (global, definido pela Foxy, versionado)
├── code, nome                      ex.: essencial, profissional, avancado
├── capacidades                     suporte · erros · dados · escrita
├── faixas permitidas + padrão      ex.: [rapida, equilibrada], padrão rapida
├── créditos por mês                ex.: 3.000
├── limite por usuário (opcional)   ex.: no máx. 30% dos créditos por pessoa
├── mascote                         escolher raposa (todos) · dar nome próprio (planos maiores)
└── retenção das conversas          30 dias (pode crescer em planos maiores)
```

**Planos (aprovado em 2026-11-01):** seis tamanhos de franquia. Os recursos acompanham o tamanho (ajustável na configuração, sem mudar código):

| Plano (créditos/mês) | 1.000 | 3.000 | 5.000 | 10.000 | 25.000 | 50.000 |
|---|---|---|---|---|---|---|
| Dúvidas de uso e erros | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| Perguntas sobre os dados ("quem me deve?") | — | — | ✔ | ✔ | ✔ | ✔ |
| Sugestão de mensagens | — | — | ✔ | ✔ | ✔ | ✔ |
| Faixas de modelo | rápida | rápida | rápida, equilibrada | rápida, equilibrada | todas | todas |
| Raposa: escolher e renomear | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |

**Créditos extras em todos os planos**, em pacotes de **1.000** (1.000, 2.000, 3.000…), somados à franquia do mês.

### 2.4 Assinatura (por API)

- Cada organização tem uma **assinatura de IA**: plano + início do ciclo + créditos extras do mês.
- Quem define é a **integração**, pela API (`PUT /v1/organizations/{id}/ai-subscription`): o sistema sabe qual plano o cliente pagou e repassa. A Foxy AI **não cobra ninguém** (coerente com a regra de não automatizar cobrança).
- Créditos acabaram → resposta `429 credit_limit` com mensagem clara; a integração soma créditos extras (`POST .../ai-credits`, múltiplos de 1.000), válidos no mês em que foram comprados.
- Mudança de plano vale na hora para conversas novas; conversas abertas mantêm a faixa com que começaram (regra do histórico que só cresce).

## 3. Permissões (três camadas, sempre a menor vence)

| Camada | Quem define | O que controla |
|---|---|---|
| 1. Credencial | Foxy, ao criar a chave | O que o sistema pode fazer na API: criar contas, configurar manual/consultas, conversar, ver uso |
| 2. Plano da organização | Integração, via assinatura | Capacidades, faixas de modelo, créditos |
| 3. Usuário | Integração, ao abrir a conversa | `allowed_capabilities` e `allowed_tools` daquela pessoa (ex.: Recepção sem financeiro) |

- A Foxy calcula **plano ∩ usuário**: a pessoa nunca recebe mais do que o plano dá, e o plano nunca passa por cima da permissão do sistema.
- Cada consulta do catálogo declara a **capacidade** a que pertence (`dados`); sem a capacidade no plano, ela nem é oferecida à IA.
- Tudo continua auditado (quem, em nome de quem, o quê).

## 4. A raposa: "a sua IA"

### 4.1 Catálogo de raposas (definido pela Foxy)

Cada raposa tem **nome unissex, visual próprio e um jeito de falar**. As regras de segurança e a precisão são as mesmas para todas — muda só a personalidade.

| Raposa | Visual (proposta) | Jeito |
|---|---|---|
| **Chico** | ruivo clássico, óculos | Objetivo e direto, respostas curtas |
| **Lud** | ártica (branca), cachecol | Acolhedora e paciente, explica passo a passo |
| **Lobinho** | cinza-prata, fone de ouvido | Animado, usa emoji com moderação |
| **Ayla** | dourada, lenço no pescoço | Formal e cuidadosa, linguagem mais séria |

Nomes definidos em 2026-11-01. Qualquer pessoa pode **renomear** a sua raposa depois (em todos os planos).

Estilo visual: ilustração plana, traço limpo, adulta e profissional (nada infantil), fundo neutro, combinando com o roxo sóbrio da Foxy.

### 4.2 Quem escolhe

- A **conta** escolhe a raposa padrão de todos.
- Cada **usuário** pode escolher a sua — e ela vale **em todos os sistemas Foxy** que ele usar, porque a escolha fica guardada na Foxy AI (`user_ref` + organização).
- Qualquer plano permite **dar outro nome** à raposa (ex.: "Lud" vira "Lulu da Recepção").
- API: `GET /v1/assistants` (catálogo com avatares), `PUT /v1/assistant-preferences` (conta ou usuário).
- Na conversa, a raposa entra nas regras fixas ("Você é a Lud, assistente da Foxy…"), escolhida na abertura.

### 4.3 Imagens

Proposta: começo com avatares **SVG simples** feitos aqui, no estilo acima, para validar a ideia; depois, se quiser, um ilustrador refaz no mesmo formato (os sistemas não mudam).

## 5. O assistente dentro dos sistemas (layout)

### 5.1 Como encaixa sem expor chaves

```text
Navegador do usuário ──► sistema (ex.: Foxy Agenda) ──► API da Foxy AI
   widget da raposa        guarda a chave, executa         regras, plano,
                            as consultas com as             modelo, uso
                            permissões do usuário
```

A Foxy entrega **duas peças prontas** para os nossos sistemas:
1. **Widget** (`foxy-assistant.js` + CSS): a janela da raposa, igual em todos os sistemas.
2. **Cliente PHP** (pacote pequeno): o sistema Laravel integra com um controller e a lista das suas consultas.

### 5.2 Layout (roxo sóbrio, unissex, simples)

```text
                                     ┌───────────────────────────────────┐
                                     │ (🦊) Lud                    ─  ✕ │  ← avatar e nome da raposa
                                     │ Assistente da Foxy                │
                                     ├───────────────────────────────────┤
                                     │ Sugestões para esta tela:         │  ← muda conforme a tela
                                     │ [Como remarco?] [Quem me deve?]   │
                                     │ [Escrever lembrete]               │
                                     │                                   │
                                     │  Você: quem mais me deve?         │
                                     │  🔎 Consultando o Financeiro…      │  ← mostra o que a IA consultou
                                     │  Lud: A Ana Souza (R$ 320,00)…   │
                                     │                                   │
                                     │  ┌ Mensagem sugerida ───────────┐ │  ← sugestão de mensagem
                                     │  │ Olá, Ana! Passando para…     │ │
                                     │  │ [Copiar] [Abrir no WhatsApp] │ │  ← quem envia é a pessoa
                                     │  └──────────────────────────────┘ │
                                     ├───────────────────────────────────┤
                                     │ [ Pergunte à Lud…          ] [➤] │
                                     │ A IA pode errar. Confira antes.   │
                                     └───────────────────────────────────┘
                                                              (🦊) ← botão flutuante
```

- Cores: primária `#5b3fd1`, escuro `#221a33`, fundo `#f5f5f8`; alertas em vermelho/verde de verdade. Sem lilás, rosa ou creme.
- Linguagem neutra ("você", "a pessoa", "o cliente"); a raposa fala na 1ª pessoa sem marcar gênero do usuário.
- Opções sempre visíveis (sugestões e botões à mostra, nada escondido em menus recolhidos).
- Celular: abre em tela cheia. Teclado e leitor de tela funcionando; contraste adequado.
- Tela "Escolha a sua raposa": as quatro raposas em cartões lado a lado, com nome, jeito e um exemplo de resposta.

## 6. Sugestão de mensagens (capacidade `escrita`)

- A pessoa pede no chat ("escreve um lembrete para a Ana", "uma mensagem de aniversário") ou clica numa sugestão.
- A IA devolve a mensagem num **cartão próprio** (`drafts: [{ "canal": "whatsapp", "texto": "..." }]`), com **Copiar** e o botão de ação que o sistema oferecer.
- Pode usar as consultas para personalizar (ex.: data do próximo atendimento).
- **A IA nunca envia nada** (decisão A5): quem clica e envia é a pessoa.

## 7. Impacto no que já existe

- **Migrações aditivas:** tabelas `integrations`, `ai_plans`, `ai_subscriptions`, `assistants` (catálogo), `assistant_preferences`; `host_profiles` ganha `integration_id`.
- **API atual continua funcionando** para chaves de organização; o novo modo "integração" é adicional.
- **Medidor:** `ai.messages` vira `ai.credits` (o registro de custo já existe; muda a unidade do limite).
- **Console de testes:** passa a usar o widget real e a tela de escolha de raposa.

## 8. Ordem de implementação (após aprovação)

1. Integração + organização por cliente (provisionar contas pela API, cabeçalho `X-Foxy-Organization`).
2. Planos, créditos, faixas de modelo e assinatura por API.
3. ✔ Permissões por usuário (`allowed_capabilities`) e capacidade por consulta.
4. Raposas: catálogo, preferências (conta e usuário), regras por raposa, avatares SVG.
5. Sugestão de mensagens (cartões `drafts`).
6. Widget + cliente PHP; console de testes usando o widget.

## 9. Decisões (aprovadas em 2026-11-01)

| # | Decisão | Decidido |
|---|---|---|
| **E1** | Hierarquia | Integração (sistema) → Organização (cliente final) → Usuário, com o manual/consultas na integração (seção 1). |
| **E2** | Unidade do plano | **Créditos Foxy proporcionais ao custo** (1 crédito = US$ 0,001), não número de mensagens. |
| **E3** | Modelos no plano | **Faixas** (rápida, equilibrada, avançada), ligadas a modelos pela configuração. |
| **E4** | Planos | Seis tamanhos (1.000, 3.000, 5.000, 10.000, 25.000 e 50.000 créditos/mês), recursos como na tabela 2.3; **créditos extras de 1.000 em 1.000 em todos**. |
| **E5** | Quem escolhe a raposa | Conta escolhe a padrão; **cada usuário pode escolher a sua**, valendo em todos os sistemas; **qualquer plano pode renomear**. |
| **E6** | Raposas iniciais | **Chico, Lud, Lobinho e Ayla** (seção 4.1), com avatares SVG feitos aqui para começar. |
| **E7** | Como aparece nos sistemas | **Widget oficial + cliente PHP** da Foxy (seção 5). O visual parte do layout da Foxy atual, **copiado e adaptado para o Core** (`Platform`), para todos os produtos novos usarem. O sistema antigo continua separado e não é dependência. |
| **E8** | Sugestão de mensagens | Capacidade `escrita` dentro do chat, com cartão "Copiar"; nunca envia sozinha. |
