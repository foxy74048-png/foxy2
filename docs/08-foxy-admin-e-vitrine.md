# Foxy Admin e vitrine da marca

> **Status:** v1.0 — aprovado em 2026-11-03 pelo responsável. Fases 1 e 2 em produção (seção 6).
> **Regra a partir de agora:** tudo que for desenvolvido usa o **Core da foxy2**. O Foxy Studio (sistema atual,
> repositório `tarologa`) só recebe melhorias e conversa com o Core **por API** — o Core nunca lê o banco dele.

## 1. Marca e produtos

- **Foxy** é a marca e a empresa. Produtos: **Foxy Studio** (gestão de atendimento, `studio.foxy.net.br`),
  **Foxy AI** (a raposa, `ai.foxy.net.br`) e **Foxy Commerce** (em breve).
- **foxy.net.br** vira a **vitrine da marca**: página completa de venda, apresentando os produtos.
- **admin.foxy.net.br** é o **Foxy Admin**: o administrativo da empresa, por cima de todos os produtos.
- **ecommerce.foxy.net.br** fica reservado ao Foxy Commerce; até ele existir, leva à vitrine (`/#produtos`).

## 2. Vitrine (foxy.net.br)

- Só a página inicial (`/`) é a vitrine, servida pela foxy2. Qualquer outro caminho do endereço antigo continua
  indo para o Studio (301 → `studio.foxy.net.br`), menos `/webhooks/*` e links assinados (`?signature=`), que o
  Studio continua atendendo no endereço antigo (o Stripe não segue redirecionamento; a assinatura inclui o host).
- Conteúdo: a proposta da marca, os produtos (Studio, AI, Commerce "em breve"), as raposas, por que a Foxy,
  segurança/LGPD e chamadas para começar grátis no Studio. Sem depoimentos inventados.

## 3. Foxy Admin (admin.foxy.net.br)

Mesmo login da equipe Foxy do console (`/console/login`). Telas:

| Tela | O que mostra / faz |
|---|---|
| **Painel** | Por produto: contas ativas, em teste, canceladas e faturamento mensal (MRR); IA: perguntas, créditos e custo do mês; promoções ativas e créditos dados de brinde no mês. |
| **Contas** | Todas as contas dos produtos (o que cada produto envia), com plano, situação, MRR e uso de IA do mês. |
| **Promoções de IA** | Campanhas controláveis de perguntas extras (seção 4). |

### Como o Admin conhece as contas de cada produto

Cada produto **envia** ao Core um retrato das suas contas (`POST /api/v1/product-snapshot`, chave do sistema):
números gerais + lista de contas (`external_ref`, nome, plano, situação, MRR, cadastro, fim do teste, última
atividade). Sem dados de contato nesta fase (LGPD: só o necessário). O Studio envia a cada hora
(`foxy-admin:enviar`). A conta do produto e o cliente da Foxy AI se ligam pelo mesmo `external_ref`
(`foxy-ws-{id}` no Studio).

O **MRR** de cada conta é o que o produto envia (o produto decide quem paga: no Studio, Stripe não isento ou
valor mensal combinado por fora). O painel soma o MRR de todas as contas não removidas, sem filtrar pela
situação — um Parceiro Fundador que paga por fora (ex.: GAAF, R$ 30) entra no faturamento.

### 3.1 Centralizado no Admin (2026-11-04)

Pedido do responsável: centralizar no Foxy Admin tudo o que der antes do Commerce.

- **Ficha da conta** (`/admin/contas/{conta}`): situação, plano, MRR, teste, saúde de uso, números do produto, contato,
  problemas (com a mensagem de ajuda pronta), raposa (uso, extras, liberação), brindes, comunicados, chamados e o histórico
  de ações — e um atalho para a conta no admin do produto.
- **Ações que o produto executa** — **comandos** (`product_commands`): estender teste, suspender/reativar, Parceiro
  Fundador, liberar a raposa, responder chamado. O Admin registra; o produto busca (`GET /api/v1/product-commands`,
  escopo `products.commands`), executa com as regras dele e devolve (`POST .../{id}/result`). Pedido sem resposta em 10
  min volta à fila. O Core nunca mexe no banco do produto.
- **Ações do próprio Core:** perguntas de presente (promoção "uma vez" só daquela conta) e mensagem de ajuda pelo
  WhatsApp da Foxy.
- **Suporte central** (`/admin/suporte`): chamados de todos os produtos (o produto envia no retrato); a resposta vira o
  comando `support.reply`.
- **Pede atenção** no painel: chamados abertos, pagantes parados, testes acabando e contas com problema.
- **Ficam no produto** (atalho no menu do Admin): planos e preços, site/termos, parcerias universitárias, conexão do
  WhatsApp e "acessar como" (precisa do login do próprio produto).
- O Studio envia o retrato a cada 10 minutos e logo depois de executar comandos.

## 4. Promoções de IA

Perguntas extras de brinde, criadas e controladas no Admin. Cada promoção tem:

- **Público:** contas novas (até N dias do cadastro) · todas as contas ativas/em teste · contas escolhidas
  (parceria, presente avulso). Opcional: só alguns planos.
- **Quantidade** (qualquer número, ex.: 100) e **frequência**: uma vez ou todo mês enquanto valer.
- **Período** (início/fim) e **situação**: ativa, pausada ou encerrada.

As perguntas viram créditos extras da Foxy AI (`ai_credit_grants`, referência `promo-{id}`), valendo no mês em que
entram. Aplicação idempotente (`ai_promotion_grants` único por promoção + cliente + período): ao salvar a promoção,
quando um cliente novo aparece na Foxy AI e todo dia (`foxy:promocoes:aplicar`). Tudo registrado: quem ganhou,
quanto e por quê.

**Chega a todas as contas, não só a quem já usou a raposa:** a promoção parte das contas do produto (`product_accounts`)
e cria o cliente da Foxy AI que ainda não existe, com o mesmo `external_ref` que o produto usa no 1º uso. A resposta do
retrato (`POST /api/v1/product-snapshot`) traz `ai_extra` (`period` + perguntas extras do mês por `external_ref`) e o
produto liga a raposa para quem ganhou, mesmo sem perguntas no plano (no Studio: `User::foxyAiExtraCreditsThisMonth`).
Sem franquia no plano, o produto põe o cliente no plano **`ia-0`** ("IA só extras": 0 incluídas + extras do mês).

## 5. Comunicados e ideias/dúvidas (fase 2)

**Comunicados** (`/admin/comunicados`): e-mail e/ou WhatsApp para o **dono de cada conta** dos produtos — novidades e
melhorias. Fluxo: escrever (com `{nome}`, `{conta}`, `{produto}`) → conferir quem recebe e a prévia → **teste para você**
→ enviar. Público: produto, situações e planos. Sai aos poucos pelo agendador (`foxy:comunicados:enviar`, a cada
minuto: até 20 e-mails e 5 WhatsApps, com pausa de 6–10 s entre WhatsApps). Histórico por envio (enviado, falhou, não
enviado). Cancelar para o que ainda está na fila.

- **Contato:** o produto envia no retrato o nome, e-mail e WhatsApp do dono (`contact_name`, `email`, `whatsapp`).
- **Descadastro (LGPD):** cada mensagem traz um link assinado (`/comunicados/sair/{envio}`); a pessoa confirma num botão
  (abrir o link não descadastra). Fica guardado **só no Core**, por canal — o retrato do produto nunca reinscreve. Avisos
  obrigatórios da conta (pagamento, segurança) continuam saindo pelos produtos.
- **Envio:** e-mail pelo SMTP da Foxy (`MAIL_*` da foxy2); WhatsApp pelo número da Foxy, a mesma Evolution API do Studio
  (`FOXY_WHATSAPP_API_URL`, `FOXY_WHATSAPP_API_KEY`, `FOXY_WHATSAPP_INSTANCE`).

**Ideias e dúvidas** (`/admin/ideias`): tudo que a raposa anota (`feature_request` → ideia; `knowledge_gap` → dúvida)
fica também no Core (`ai_insights`, só o resumo), de **todos os produtos**, com produto e conta e situação (nova,
avaliando, vai ser feita, feita, descartada).

## 6. Fases

1. ✔ Vitrine + Admin (painel, contas) + promoções de IA + envio do retrato pelo Studio.
2. ✔ Comunicados (e-mail e WhatsApp pelo número da Foxy, com descadastro); ideias/dúvidas da raposa de todos os produtos no Admin.
3. Assinaturas controladas pelo Admin e login único (Identity do Core), junto com o Commerce.
