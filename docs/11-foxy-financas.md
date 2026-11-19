# Foxy Finanças — Definição de Produto (proposta)

> **Status:** v0.2 (2026-11-18). Decisões D-F1 a D-F6 tomadas (seção 10); falta D-F7. Arquitetura: `12-financas-arquitetura.md` (para aprovação). Nada foi implementado.
> **Ordem:** terceiro produto, sobre o Core da foxy2 (mesmo app do Foxy AI, do Foxy Admin e do Foxy Commerce).
> **Nome:** "Foxy Finanças" em `financas.foxy.net.br`.
> **Mudança em relação à v0.1:** o público passa a ser **pessoa física e também MEI / pequeno negócio com 1 ou 2 sócios** (dividir pró-labore, retiradas e lucro). A importação de extrato copia e adapta a do Foxy Studio; nada de conexão automática com banco por enquanto.
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

Dois públicos desde o MVP, no mesmo sistema:

- **Pessoa física:** 20–55 anos, com 1 a 4 contas/cartões, que já tentaram planilha ou app e desistiram. B2C, preço baixo.
- **MEI e pequeno negócio com 1 ou 2 sócios:** quer saber quanto o negócio ganhou, quanto cada sócio pode tirar, **dividir o pró-labore e o lucro** pela parte de cada um, registrar o que cada sócio pôs (aporte) e tirou (retirada) e fazer o **acerto entre sócios** ("você tirou R$ 400 a mais este mês"). Também lembra o DAS do MEI. Ponte natural com clientes do Foxy Studio e do Foxy Commerce.
- **Casal/família:** finanças compartilhadas (cada pessoa com o próprio login, contas da casa em comum).

A mesma pessoa pode ter as duas coisas: a **carteira pessoal** e a **carteira do negócio**, e troca entre elas no topo da tela. A retirada do negócio aparece como entrada na carteira pessoal dela, sem precisar lançar duas vezes.

- **Fora:** empresas com contabilidade completa (isso é ERP), nota fiscal, folha de pagamento, investimentos complexos (carteira de ações, IR de renda variável).

## 4. Funcionalidades

### 4.1. MVP (fase 1)

| Área | O que tem |
|---|---|
| **Contas e cartões** | Conta corrente, poupança, carteira (dinheiro), cartão de crédito (com dia de fechamento e vencimento) e vale (VR/VA). Saldo de cada uma. |
| **Lançamentos** | Gasto, recebimento e transferência entre contas. Valor, data, descrição, categoria, conta, observação. Parcelado no cartão (gera as parcelas nas faturas futuras). Repetição mensal (aluguel, salário). Tudo editável e excluível. |
| **Categorias** | Lista pronta em português (Mercado, Delivery, Transporte, Moradia, Saúde, Lazer, Assinaturas, Educação...), com subcategorias; a pessoa cria, renomeia, junta e esconde. |
| **Categorização automática** | Regras aprendidas ("IFOOD" → Delivery); quando a pessoa corrige, vira regra. A IA só entra quando nenhuma regra serve (custo baixo). |
| **Importação (sem conexão com banco)** | Copiada e adaptada do Foxy Studio, que já está testada com arquivos reais: extrato **OFX** (todos os grandes bancos), **CSV/Excel** (a pessoa aponta as colunas uma vez; crédito e débito em colunas separadas), **PDF** de extrato e de fatura (inclusive o agrupado por dia do PicPay/Nubank, com nome quebrado em duas linhas). Linha que não deu para ler é avisada, não some. Não importa a mesma linha duas vezes (reenviar o mesmo arquivo é seguro). **Conciliar:** se o gasto já foi lançado à mão ou pelo WhatsApp, a linha do extrato só confirma, não duplica. Revisar linha a linha ou **lançar em lote** com a categoria sugerida; dividir uma linha em partes; ignorar; desfazer a importação inteira. Também importa a **planilha antiga** de controle (de outro app ou feita à mão). |
| **Sócios (carteira do negócio)** | Cada sócio com a sua parte (%). Pró-labore e retiradas por sócio; aporte de sócio; "Lucro do mês" e quanto cabe a cada um; acerto entre sócios. A retirada vira entrada na carteira pessoal do sócio, se ele usar o Foxy Finanças. Lembrete do DAS (MEI). Separar no extrato o que é pessoal e o que é do negócio (botão "isso é pessoal" move a linha para a carteira pessoal). |
| **WhatsApp** | A pessoa manda "gastei 42,90 no mercado no débito" ou a foto do cupom; a raposa lança e responde confirmando, com botão para corrigir. Resumo semanal opcional. |
| **Faturas do cartão** | Fatura atual e futuras, o que já está comprometido com parcelas nos próximos meses, aviso de vencimento. |
| **Orçamento** | Limite por categoria no mês (ex.: Delivery R$ 300); barra de quanto já foi; aviso ao chegar em 80% e 100%. |
| **Painéis** | Início com: quanto entrou, quanto saiu, quanto sobra; top categorias; top lugares; evolução de 6/12 meses; comparação com o mês anterior ("o que mudou"); calendário de contas a vencer. |
| **Indicadores para economizar** (pedido em 2026-11-19) | Calculados por código, sem IA: **quanto da renda você guardou** no mês (taxa de poupança) e a média de 3 meses; **no ritmo atual, o mês fecha em R$ X** (gasto por dia × dias que faltam); **o que mais subiu** em relação à média dos 3 meses anteriores; **gastinhos** (compras pequenas e frequentes — café, delivery, apps — somadas no mês e no ano); **assinaturas encontradas** com o custo por ano; **comprometido com parcelas** nos próximos meses; **reserva de emergência**: quantos meses das suas despesas o que está guardado cobre; e **"se cortar pela metade"**: quanto guardaria por ano reduzindo as 3 categorias que mais cresceram. A raposa (fatia 8) só transforma isso em frases e dicas. |
| **Metas de guardar dinheiro** (adiantado da fase 2) | Meta com valor e prazo (reserva de emergência, viagem, trocar o carro), ligada a uma conta (ex.: poupança) ou a transferências marcadas para ela; mostra o quanto falta e **quanto guardar por mês** para chegar no prazo. |
| **Quem me deve / a quem eu devo** (pedido em 2026-11-19) | Lista de pessoas (amigos, família) com o **saldo de cada uma**: "o João te deve R$ 150", "você deve R$ 40 à Carla". Três jeitos de registrar: **emprestei / peguei emprestado** dinheiro (pode sair de uma conta: vira transferência para uma conta interna "A receber de amigos", então **não conta como gasto**); **dividir uma conta** (ex.: o bar de R$ 200 dividido com 3 amigos — você pagou, cada um te deve R$ 50, e o seu gasto fica só com a sua parte); e **objetos emprestados** (livro, furadeira), sem valor, com a data e "já devolveu". Pagamento parcial ("o João pagou R$ 50"), histórico por pessoa e botão para **mandar um lembrete pelo WhatsApp** — abre o WhatsApp da própria pessoa com o texto pronto, nada automático (a Foxy não cobra ninguém). |
| **Raposa (Foxy AI)** | Assistente do sistema (já existe no Core) com ferramentas de leitura: perguntas sobre os dados, explicação do mês, assinaturas encontradas, sugestão de orçamento. |
| **Exportar** | Toda lista e relatório em PDF e planilha. |
| **Privacidade** | Dados só da pessoa (e de quem ela convidar); exportar tudo e apagar a conta a qualquer momento (LGPD). |

### 4.2. Fase 2

- ~~Metas~~ e ~~detector de assinaturas~~: foram para o MVP (2026-11-19).
- **Dívidas com banco e financiamentos** (quanto falta, juros, simulação de quitar antes). As dívidas entre amigos foram para o MVP ("Quem me deve").
- **Relatório mensal da raposa** por e-mail/WhatsApp: "3 coisas que mudaram e 1 sugestão".
- **Família/casal** completo: divisão de despesas ("quem deve quanto a quem").

### 4.3. Fase 3

- **Integrações externas** (Open Finance por agregador autorizado pelo Banco Central, ex.: Pluggy, Belvo): **só se o produto pegar** (D-F6). Tem custo por conexão e exige contrato da Foxy.
- Relatório para o contador (MEI: faturamento anual para a declaração DASN-SIMEI).
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

Detalhado em `12-financas-arquitetura.md`. Em resumo: **Organization** do Core = uma **carteira** (tipo `pessoal` ou `negocio`); cada pessoa tem o próprio login e pode estar em várias carteiras (as memberships que já existem); tabelas novas com prefixo `finance_`, todas com `BelongsToOrganization`.

## 7. O que reaproveitar da foxy2

| Já existe | Uso no Finanças |
|---|---|
| Core: organizações, memberships, convites, login | Pessoa/casal, convite do parceiro |
| Platform\Billing (Stripe) + Foxy Admin | Assinatura do plano, promoções, painel de MRR por produto |
| Foxy AI (assistente, HostProfile, ToolCatalog, créditos) | A raposa do Finanças, como `CommerceHost`/`CommerceFox` |
| Commerce\Imports (`Sheet`, `PdfTable`) | Ler .xlsx/.csv/PDF com tabela |
| **Foxy Studio** — `StatementParser`, `StatementImporter`, `FinancialImporter`, revisão de linhas (cópia adaptada; a foxy2 nunca lê o banco do Studio) | Extrato OFX/CSV/PDF, repetidos, conciliar, lote, dividir, desfazer, planilha antiga |
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
| Compartilhado | R$ 16,90 | Pessoal + até 4 pessoas na mesma carteira: casal, família **ou sócios** do negócio |

Anual com desconto. Aprovado (D-F4). A carteira do negócio com sócios usa o plano Compartilhado; MEI sozinho cabe no Pessoal. Cada carteira tem o seu plano; a carteira pessoal do sócio continua grátis ou Pessoal, à parte.

## 9. Riscos e cuidados

- **Dado sensível**: observações criptografadas no banco (a descrição não, porque busca e regras dependem dela — ver docs/12 §10), nunca enviados à IA além do necessário para a pergunta, nunca no snapshot do Foxy Admin (lá só contagem e plano).
- **Senha de banco**: nunca pedir. Open Finance só por agregador autorizado (fase 3).
- **Abandono**: o produto vive ou morre pelo esforço de lançar. WhatsApp e importação estão no MVP por isso.
- **Custo de IA num plano barato**: regras antes da IA, lote, modelo barato, insights calculados por código.
- **MEI**: SaaS B2C cabe na mesma atividade do Studio e do Commerce; sem novo risco fiscal além do já anotado.

## 10. Decisões para o responsável

Respostas de 2026-11-18:

- **D-F1 — Nome e endereço:** ✔ "Foxy Finanças" em `financas.foxy.net.br`.
- **D-F2 — Público do MVP:** ✔ pessoa física **e** MEI / pequeno negócio com 1 ou 2 sócios (pró-labore, retiradas, lucro, acerto entre sócios).
- **D-F3 — Casal/família:** ✔ base no MVP (convidar pessoas para a mesma carteira); divisão "quem deve a quem" na fase 2.
- **D-F4 — Planos e preços:** ✔ seção 8, com plano grátis.
- **D-F5 — WhatsApp no MVP:** ✔ pelo número da Foxy (uma instância para todo o produto).
- **D-F6 — Integrações externas / Open Finance:** ✔ aguardar. Importação manual de arquivo (copiada do Studio); integração só se o produto pegar.
- **D-F7 — Primeiros usuários:** *em aberto.* Quem testa primeiro e se entra no ar com cadastro fechado, como o Commerce (`SIGNUP_OPEN`).
