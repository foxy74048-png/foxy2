# Foxy Admin e vitrine da marca

> **Status:** v1.0 — aprovado em 2026-11-03 pelo responsável. Fase 1 em implementação (seção 6).
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

## 4. Promoções de IA

Perguntas extras de brinde, criadas e controladas no Admin. Cada promoção tem:

- **Público:** contas novas (até N dias do cadastro) · todas as contas ativas/em teste · contas escolhidas
  (parceria, presente avulso). Opcional: só alguns planos.
- **Quantidade** (qualquer número, ex.: 100) e **frequência**: uma vez ou todo mês enquanto valer.
- **Período** (início/fim) e **situação**: ativa, pausada ou encerrada.

As perguntas viram créditos extras da Foxy AI (`ai_credit_grants`, referência `promo-{id}`), valendo no mês em que
entram. Aplicação idempotente (`ai_promotion_grants` único por promoção + cliente + período): ao salvar a promoção,
quando um cliente novo aparece na Foxy AI e todo dia (`foxy:promocoes:aplicar`). Tudo registrado: quem ganhou,
quanto e por quê. Obs.: o brinde só é usado por contas que têm a raposa ligada no produto.

## 5. Comunicados (fase 2)

E-mail e WhatsApp para clientes dos produtos (público por produto, plano e situação), com prévia, envio aos poucos,
histórico e **descadastro** (LGPD); avisos obrigatórios continuam saindo sempre. Exige que os produtos enviem
contato com consentimento — entra na fase 2.

## 6. Fases

1. **Vitrine + Admin (painel, contas) + promoções de IA + envio do retrato pelo Studio.** ← em implementação
2. Comunicados (e-mail, depois WhatsApp pelo número da Foxy); ideias/dúvidas da raposa de todos os produtos no Admin.
3. Assinaturas controladas pelo Admin e login único (Identity do Core), junto com o Commerce.
