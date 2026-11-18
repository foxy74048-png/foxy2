# Foxy Finanças — Definição de Produto (proposta)

> **Status:** v0.1 (2026-11-18), proposta para aprovação. Nada foi implementado.
> **Ordem:** terceiro produto, sobre o Core da foxy2 (mesmo app do Foxy AI, do Foxy Admin e do Foxy Commerce).
> **Nome provisório:** "Foxy Finanças" (ver D-F1). Endereço sugerido: `financas.foxy.net.br`.
> **Processo:** igual ao Commerce: (1) aprovar este documento e as decisões D-F; (2) arquitetura e fatias; (3) código só depois da aprovação da etapa 2.

---

## 1. Problema

A maioria das pessoas não sabe para onde vai o próprio dinheiro. O salário entra, o cartão fecha, e no fim do mês sobra menos do que o esperado (ou falta). As causas mais comuns:

- gastos pequenos e frequentes (delivery, apps, mercado "de passagem") que somados pesam muito;
- assinaturas esquecidas (streaming, apps, academia que não usa);
- parcelas no cartão que se acumulam e comprometem os meses seguintes;
- o dinheiro espalhado em vários bancos e cartões, sem visão única;
- planilhas que dão trabalho e são abandonadas na segunda semana.

Os apps que existem (Mobills, Organizze, Minhas Finanças...) pedem que a pessoa digite tudo ou cobram caro pela conexão com o banco, e quase nenhum **explica** o que os números querem dizer.

## 2. Proposta de valor

*"Saiba onde o seu dinheiro vai, sem planilha: mande o gasto pelo WhatsApp, importe o extrato e a fatura, e a raposa mostra onde dá para economizar."*

Três pilares:

1. **Registrar sem esforço** — o maior motivo de abandono é o trabalho de lançar. Por isso: lançamento em 3 toques, mensagem no WhatsApp ("gastei 42 no mercado"), importação de extrato (OFX/CSV) e de fatura do cartão (PDF), categorização automática.
2. **Ver onde gasta mais** — painéis simples e bonitos: por categoria, por lugar, por cartão, mês a mês, para onde foi o aumento, quanto as parcelas já comprometem dos próximos meses.
3. **A raposa (Foxy AI) como conselheira** — responde perguntas sobre os dados ("quanto gastei com delivery este ano?"), aponta padrões ("seu mercado subiu 30% desde agosto"), encontra assinaturas esquecidas e sugere um orçamento possível.

**O Foxy Finanças nunca move dinheiro**: não paga contas, não faz Pix, não pede senha de banco. Só organiza e explica (coerente com a regra da Foxy de não automatizar cobrança/movimentação de dinheiro).

## 3. Público

- **Primeiro público:** pessoas físicas no Brasil, 20–55 anos, com 1 a 4 contas/cartões, que já tentaram planilha ou app e desistiram. B2C, preço baixo.
- **Casal/família:** finanças compartilhadas (cada pessoa com o próprio login, contas da casa em comum) — ver D-F3.
- **Depois (fase 3):** MEI e autônomos que misturam conta pessoal e do negócio, com separação "pessoal × trabalho". Ponte natural com clientes do Foxy Studio e do Foxy Commerce.
- **Fora:** empresas com contabilidade (isso é ERP), investimentos complexos (carteira de ações, IR de renda variável).

## 4. Funcionalidades

### 4.1. MVP (fase 1)

| Área | O que tem |
|---|---|
| **Contas e cartões** | Conta corrente, poupança, carteira (dinheiro), cartão de crédito (com dia de fechamento e vencimento) e vale (VR/VA). Saldo de cada uma. |
| **Lançamentos** | Gasto, recebimento e transferência entre contas. Valor, data, descrição, categoria, conta, observação. Parcelado no cartão (gera as parcelas nas faturas futuras). Repetição mensal (aluguel, salário). Tudo editável e excluível. |
| **Categorias** | Lista pronta em português (Mercado, Delivery, Transporte, Moradia, Saúde, Lazer, Assinaturas, Educação...), com subcategorias; a pessoa cria, renomeia, junta e esconde. |
| **Categorização automática** | Regras aprendidas ("IFOOD" → Delivery); quando a pessoa corrige, vira regra. A IA só entra quando nenhuma regra serve (custo baixo). |
| **Importação** | Extrato OFX (todos os grandes bancos exportam) e CSV (Nubank, Inter, C6...); fatura do cartão em PDF (reaproveita a leitura de PDF com tabela do Commerce). Detecta duplicados. Desfazer a importação inteira. |
| **WhatsApp** | A pessoa manda "gastei 42,90 no mercado no débito" ou a foto do cupom; a raposa lança e responde confirmando, com botão para corrigir. Resumo semanal opcional. |
| **Faturas do cartão** | Fatura atual e futuras, o que já está comprometido com parcelas nos próximos meses, aviso de vencimento. |
| **Orçamento** | Limite por categoria no mês (ex.: Delivery R$ 300); barra de quanto já foi; aviso ao chegar em 80% e 100%. |
| **Painéis** | Início com: quanto entrou, quanto saiu, quanto sobra; top categorias; top lugares; evolução de 6/12 meses; comparação com o mês anterior ("o que mudou"); calendário de contas a vencer. |
| **Raposa (Foxy AI)** | Assistente do sistema (já existe no Core) com ferramentas de leitura: perguntas sobre os dados, explicação do mês, assinaturas encontradas, sugestão de orçamento. |
| **Exportar** | Toda lista e relatório em PDF e planilha. |
| **Privacidade** | Dados só da pessoa (e de quem ela convidar); exportar tudo e apagar a conta a qualquer momento (LGPD). |

### 4.2. Fase 2

- **Metas** (reserva de emergência, viagem, quitar dívida) com quanto guardar por mês.
- **Detector de assinaturas e cobranças repetidas** com "quanto isso custa por ano".
- **Dívidas e empréstimos** (quanto falta, juros, simulação de quitar antes).
- **Relatório mensal da raposa** por e-mail/WhatsApp: "3 coisas que mudaram e 1 sugestão".
- **Família/casal** completo: divisão de despesas ("quem deve quanto a quem").

### 4.3. Fase 3

- **Open Finance** (conexão automática com o banco) por um agregador autorizado pelo Banco Central (ex.: Pluggy, Belvo). Tem custo por conexão e exige contrato e cadastro da Foxy — passo do responsável, no fim.
- **Pessoal × trabalho** para MEI/autônomos (com relatório para o contador e o DAS).
- App instalável (PWA) com atalho "lançar gasto".

## 5. Como a raposa ajuda (detalhe)

A raposa segue o desenho já aprovado do Foxy AI (docs/02 e docs/05): o produto declara **ferramentas somente leitura** e o Core as executa com as permissões da pessoa logada. Ferramentas propostas:

- `resumo_periodo(de, ate)` — entradas, saídas, saldo, por categoria;
- `gastos_por(categoria|lugar|conta, periodo)`;
- `comparar_periodos(a, b)` — o que subiu e o que desceu;
- `assinaturas_detectadas()`;
- `faturas_futuras()` — parcelas comprometidas;
- `orcamento_status(mes)`.

Usos que não são conversa, para controlar custo:

- **Categorizar** só o que as regras não resolvem, em lote (vários lançamentos por chamada, modelo mais barato).
- **Ler mensagem e foto do WhatsApp** (texto → lançamento estruturado; foto do cupom → valor, lugar e data).
- **Insights do mês** calculados por código (variações, picos, assinaturas) e só **redigidos** pela IA — a IA não faz conta, explica a conta já feita. Isso evita número inventado e custa pouco.

Créditos de IA por plano, como nos outros produtos (docs/07).

## 6. Modelo de dados (rascunho, sobre o Core)

- **Organization** do Core = a "carteira" da pessoa ou da casa. Cada pessoa tem o próprio login; convidar o parceiro usa as **memberships** que já existem.
- Tabelas novas com prefixo `finance_`: `accounts`, `cards` (ou conta tipo cartão), `transactions` (valor em centavos, sinal pelo tipo, `series_id`/parcela igual ao Commerce), `categories` (árvore simples), `category_rules`, `budgets`, `imports` (para desfazer), `invoices` (faturas).
- Tudo com `BelongsToOrganization`, igual ao Commerce.

## 7. O que reaproveitar da foxy2

| Já existe | Uso no Finanças |
|---|---|
| Core: organizações, memberships, convites, login | Pessoa/casal, convite do parceiro |
| Platform\Billing (Stripe) + Foxy Admin | Assinatura do plano, promoções, painel de MRR por produto |
| Foxy AI (assistente, HostProfile, ToolCatalog, créditos) | A raposa do Finanças, como `CommerceHost`/`CommerceFox` |
| Commerce\Imports (`Sheet`, `PdfTable`) | Importar CSV e fatura em PDF |
| Commerce\Insights (`Export`, `Csv`) | PDF e planilha de toda lista |
| Commerce\Finance (parcelas, repetição por `series_id`) | Mesmo padrão de parcelas e repetição |
| Platform\Messaging (WhatsApp via Evolution) | Lançar pelo WhatsApp e avisos |
| Platform\Maintenance (backup e saúde) | Já cobre o banco inteiro |

Peças hoje dentro de `Commerce` que os dois produtos usarão (`Sheet`, `PdfTable`, `Export`, `Csv`) devem **subir para Platform** na primeira fatia, sem mudar o comportamento do Commerce.

## 8. Planos (proposta inicial)

| Plano | Preço/mês | Para quem |
|---|---|---|
| Grátis | R$ 0 | 2 contas, lançamento manual, painéis básicos, 30 perguntas à raposa/mês |
| Pessoal | R$ 9,90 | Contas ilimitadas, importação, WhatsApp, orçamento, raposa com mais créditos |
| Casal/Família | R$ 16,90 | Pessoal + até 4 pessoas na mesma carteira |

Anual com desconto. Valores a confirmar (D-F4).

## 9. Riscos e cuidados

- **Dado sensível**: valores e descrições criptografados no banco (cast `encrypted` do Laravel nos campos de texto livre), nunca enviados à IA além do necessário para a pergunta, nunca no snapshot do Foxy Admin (lá só contagem e plano).
- **Senha de banco**: nunca pedir. Open Finance só por agregador autorizado (fase 3).
- **Abandono**: o produto vive ou morre pelo esforço de lançar. WhatsApp e importação estão no MVP por isso.
- **Custo de IA num plano barato**: regras antes da IA, lote, modelo barato, insights calculados por código.
- **MEI**: SaaS B2C cabe na mesma atividade do Studio e do Commerce; sem novo risco fiscal além do já anotado.

## 10. Decisões para o responsável

- **D-F1 — Nome e endereço.** Sugestão: "Foxy Finanças" em `financas.foxy.net.br`. Alternativa: "Foxy Financeiro".
- **D-F2 — Público do MVP.** Sugestão: pessoa física. MEI/autônomo fica para a fase 3.
- **D-F3 — Casal/família no MVP?** Sugestão: só a base (convidar uma pessoa para a mesma carteira) no MVP; divisão de despesas na fase 2.
- **D-F4 — Planos e preços** da seção 8, incluindo ter plano grátis.
- **D-F5 — WhatsApp no MVP?** Sugestão: sim, pelo número da Foxy (uma instância só para todo o produto, não uma por pessoa).
- **D-F6 — Open Finance.** Sugestão: fase 3, depois de validar com usuários reais.
- **D-F7 — Primeiros usuários.** Quem testa primeiro (você e pessoas próximas?) e se entra no ar fechado, como o Commerce (`SIGNUP_OPEN`).
