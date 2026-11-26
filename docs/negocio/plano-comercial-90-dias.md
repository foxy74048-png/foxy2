# Plano comercial — 90 dias (dez/2026 a fev/2027)

**Objetivo:** sair de 1 conta pagante (GAAF, R$ 30) para **20 contas pagantes** do Foxy Studio, com MRR de pelo menos
**R$ 800**, e saber de qual canal veio cada uma. O número importa menos que aprender o que faz alguém pagar.

Público: saúde e beleza no geral — esteticista, manicure, terapeuta, massoterapeuta, studio de pilates/yoga
(decisão da fundadora). Dentista e médico com convênio ficam fora por enquanto (ver o comparativo, seção 3).
Concorrentes e argumentos: `comparativo-concorrentes.md`.

Painel para acompanhar: **Foxy Admin → Métricas** (cadastros, ativação em 7 dias, conversão, churn) e o resumo
"Crescimento" no Painel.

## 0. Antes de começar (semana 1)

1. **Deploy do Studio** com tudo que está no `main` (agenda, fichas no atendimento, agendar depois do formulário).
2. **Escolher a oferta de lançamento** e não mudar por 90 dias. Sugestão:
   - **Parceira Fundadora:** os primeiros 20 pagam o plano **Profissional (R$ 39,90) por R$ 29,90 para sempre**,
     enquanto a assinatura estiver ativa, em troca de um depoimento e de uma conversa de 20 minutos por mês.
     **Como ligar (sem programar):** o checkout do Studio já aceita código promocional. No Stripe:
     (1) Produtos → cupom de **R$ 10,00 de desconto**, duração **para sempre**, limitado ao produto do plano
     Profissional e a **20 resgates**; (2) criar o **código promocional** `FUNDADORA` para esse cupom;
     (3) no servidor do foxy2, `FOXY_FOUNDER_CODE=FUNDADORA` no `.env` (sem `sed -i`) e `config:cache` — a oferta
     aparece em foxy.net.br → Preços. Esgotou: tirar a linha do `.env` e `config:cache`.
   - **Migração grátis:** "eu passo sua agenda, clientes e fichas do sistema antigo para cá".
   - Teste grátis como já está no sistema.
3. **Página de vendas (foxy.net.br)** com um bloco por público (estética, unhas, terapias), o preço à vista,
   "a equipe já vem no plano" e "migração grátis". Um botão só: começar o teste. ✔ Feito em 26/11 (seções
   "Para você" e "Preços"; a oferta aparece quando `FOXY_FOUNDER_CODE` estiver preenchido).
4. **Roteiro de demonstração de 15 minutos** em vídeo (tela gravada), na ordem que vende:
   cliente preenche a anamnese → agenda pelo link → lembrete no WhatsApp → ficha aberta no atendimento → financeiro do dia.
5. **Lista de 100 nomes** de profissionais que você conhece ou acha no Instagram da sua cidade, em planilha
   (nome, segmento, @, WhatsApp, sistema que usa hoje, data do contato, resposta).

## 1. Mês 1 — vender na mão (meta: 8 pagantes)

O objetivo é conversar com gente de verdade, não automatizar.

| Semana | O que fazer | Meta da semana |
|---|---|---|
| 1 | Oferta, página, vídeo, lista de 100 (seção 0). Avisar quem já testou e não pagou: oferta de Fundadora. | 14 contas existentes contatadas |
| 2 | 25 mensagens diretas (WhatsApp/Instagram), personalizadas: "vi seu trabalho de X, uso/fiz um sistema que…". Oferecer a demonstração ao vivo de 15 min. | 25 contatos, 5 demonstrações |
| 3 | Mais 25 contatos. Fazer as demos, migrar os dados de quem topar **no mesmo dia**. | 5 testes com dados migrados |
| 4 | Acompanhar quem está em teste: ligar no dia 3 e no dia 6 do teste ("conseguiu marcar o 1º atendimento?"). | 8 pagantes |

**Regra de ouro:** nenhuma conta em teste fica sem o 1º agendamento até o 3º dia. Se não agendou, você liga e faz junto.
É o número "Ativação em 7 dias" das Métricas.

## 2. Mês 2 — indicação e conteúdo (meta: +7 pagantes, total 15)

1. **Indicação:** cada pagante que indicar outra que vire pagante ganha 1 mês grátis, e quem chega também.
   (Pode ser controlado à mão no começo, pelo "Valor mensal combinado" da conta.)
2. **Depoimentos:** pedir às Fundadoras um vídeo curto ou print de WhatsApp. Colocar na página de vendas.
3. **Conteúdo 3x por semana** no Instagram da Foxy, sempre resolvendo um problema da profissional:
   "como parar de perder cliente que falta" (lembrete), "anamnese pelo link antes da sessão", "quanto você
   faturou este mês" (financeiro). Cada post termina com o link do teste.
4. **Grupos e comunidades:** entrar em 5 grupos de esteticistas/terapeutas e responder dúvidas por 2 semanas antes
   de falar do sistema.
5. Continuar 15 contatos diretos por semana.

## 3. Mês 3 — parcerias e preço (meta: +5 pagantes, total 20)

1. **Parcerias com quem já fala com o público:** cursos de estética e unhas, distribuidoras de produtos,
   professores de terapias. Proposta: a aluna/cliente ganha 2 meses grátis; o parceiro recebe uma comissão fixa
   por pagante (combinada por fora, sem cobrança automática). Meta: 2 parceiros ativos.
2. **Revisar o preço** com o que aprendeu: se quase ninguém escolhe Entrada (R$ 12,90), tirar da página; se
   Essencial e Profissional brigam entre si, juntar. Preço só muda para quem entra depois.
3. **Escolher o canal que mais trouxe pagante** (planilha) e dobrar o tempo nele. Parar o que não trouxe ninguém.
4. Preparar o próximo plano de 90 dias com os números reais.

## 4. Rotina semanal (1 hora, toda segunda)

1. Abrir Foxy Admin → Métricas: cadastros, ativação, conversão, quem saiu.
2. "Pede atenção" no Painel: testes acabando e pagantes parados → mensagem para cada um.
3. Planilha de contatos: quantos contatos, demos, testes e pagantes na semana, e de onde vieram.
4. Uma coisa para melhorar no produto, escolhida a partir do que as clientes reclamaram (não do que você acha).

## 5. Números para olhar

| Número | Onde | Meta em 90 dias |
|---|---|---|
| Pagantes | Admin → Painel | 20 |
| MRR | Admin → Painel | ≥ R$ 800 |
| Ativação em 7 dias | Admin → Métricas | ≥ 60% |
| Conversão de teste em pagante | Admin → Métricas | ≥ 25% |
| Churn do mês | Admin → Métricas | ≤ 5% |
| Contato → demonstração | planilha | ≥ 20% |
| Demonstração → pagante | planilha | ≥ 30% |

## 6. Riscos e o que fazer

- **Tempo:** uma pessoa só para vender, dar suporte e programar. Reservar blocos fixos: manhã para vendas,
  tarde para produto. Nada de funcionalidade nova que nenhuma cliente pediu nesses 90 dias.
- **Objeção do Pix/sinal:** responder com o argumento do comparativo ("o dinheiro cai direto na sua conta").
  Se mais de 3 em cada 10 desistirem por isso, reabrir a decisão sobre pagamento (ver `docs/DECISOES.md` do Studio).
- **MEI:** o faturamento de 2026 já passou do limite; a mudança para ME no Simples está prevista para 2027.
  Confirmar com a contabilidade se o CNAE cobre a venda de software por assinatura antes de crescer.
- **Cliente que entra e não usa:** é o maior risco de churn. A regra do 1º agendamento em 3 dias resolve a maior parte.
