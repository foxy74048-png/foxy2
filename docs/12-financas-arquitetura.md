# Foxy Finanças — Arquitetura e Fatias

> **Status:** v0.1 (2026-11-18), para aprovação. Produto: `11-foxy-financas.md` v0.2.
> **Regra:** código só depois da aprovação deste documento (decisões da seção 11).

---

## 1. Resumo

| Item | Escolha |
|---|---|
| Onde roda | No app foxy2 (mesmo deploy do Foxy AI, do Foxy Admin e do Commerce), módulo novo `app/Finance`. Rotas presas ao endereço `financas.foxy.net.br` (`config('foxy.hosts.financas')`), como o Commerce. |
| Stack | A de sempre: PHP 8.4, Laravel 13, PostgreSQL (SQLite nos testes), Blade com o visual do Core (roxo sóbrio, neutro). |
| Novas dependências | Nenhuma. Leitura de .xlsx/.csv/PDF/OFX sem biblioteca, como hoje. |
| Carteiras | Cada carteira é uma `Organization` da integração "Foxy Finanças". Dados em tabelas `finance_*` com `BelongsToOrganization`. Uma pessoa pode estar em várias carteiras (a pessoal e a do negócio) e troca no topo. |
| Importação | Cópia adaptada do Foxy Studio (`StatementParser`, `StatementImporter`, `FinancialImporter`, revisão de linhas), **com os testes dele** trazidos junto, para garantir a mesma leitura dos arquivos reais. A foxy2 nunca lê o banco do Studio. |
| Raposa | Pela API da Foxy AI, como o Commerce (`FinanceHost` + `FinanceFox`, ferramentas só de leitura). |
| Dinheiro | Nenhum movimento real. Só a assinatura do próprio Foxy Finanças passa pelo Stripe. |

## 2. O que já existe e é reaproveitado

| Peça | Onde está | Uso |
|---|---|---|
| Login, cadastro, verificação, senha | Platform\Identity (feito no Commerce) | Igual, na tela do Finanças |
| Memberships e convites | Platform\Memberships | Casal, família e sócios na mesma carteira |
| Assinatura Stripe + webhook | Platform\Billing | Planos Grátis / Pessoal / Compartilhado |
| Entitlements | Platform\Entitlements | Limite de contas e de pessoas no plano Grátis |
| Foxy Admin | ProductAccount direto (mesmo app) | Contas, MRR, suporte, comunicados e promoções sem nada novo |
| E-mail e WhatsApp de saída | Platform\Messaging | Avisos, resumo semanal, resposta ao lançamento |
| Backup e saúde | Platform\Maintenance | Já cobre o banco inteiro |
| `Sheet`, `PdfTable`, `Export`, `Csv` | Hoje em `Commerce\Imports` / `Commerce\Insights` | **Sobem para `Platform\Imports` e `Platform\Exports`** (fatia 0) |
| Parcelas e repetição por `series_id` | `Commerce\Finance` | Mesmo padrão nos lançamentos |
| Extrato (OFX/CSV/PDF), repetidos, conciliar, lote, dividir, desfazer, planilha antiga | **Foxy Studio** `Services/Statements/*`, `Services/Imports/FinancialImporter`, `StatementLineController` | Cópia adaptada: sem cliente/cobrança/assinatura do Studio; no lugar, categoria, conta, sócio e carteira |

**Novo na Platform:** receber mensagem de WhatsApp (webhook da Evolution API). Hoje a foxy2 só envia. Fica em `Platform\Messaging\Inbound` para o Commerce poder usar depois.

## 3. Estrutura de pastas

```text
app/Finance/
├── Wallets/        Wallet (1:1 com Organization): tipo pessoal|negocio, MEI, dia do DAS; troca de carteira
├── Accounts/       Account (corrente, poupança, dinheiro, cartão, vale), saldo, fatura calculada
├── Categories/     Category (árvore de 2 níveis, lista pronta), CategoryRule, Categorizer
├── Transactions/   Transaction, TransactionService (única porta para criar/alterar lançamentos)
├── Statements/     StatementParser, StatementImporter, StatementImport, StatementLine (cópia do Studio)
├── Imports/        LegacySheetImport (planilha antiga, mapeamento de colunas — FinancialImporter do Studio)
├── Partners/       Partner (sócio e parte %), PartnerSettlement (lucro do mês, quanto cabe a cada um, acerto)
├── Budgets/        Budget, avisos de 80% e 100%
├── Insights/       painéis, relatórios, "o que mudou", detector de assinaturas (por código)
├── WhatsApp/       vínculo do número, leitura da mensagem/foto → lançamento, respostas
├── Assistant/      FinanceHost (manual + tools de leitura), FinanceFox
├── Billing/        Plans (Grátis, Pessoal, Compartilhado)
└── Http/           controllers do app
```

Regras: só `TransactionService` grava lançamento (tela, importação, WhatsApp e sócios passam por ele); `Finance` só usa `Platform` pelas interfaces públicas; `Platform` não conhece `Finance`.

## 4. Banco de dados (tudo com `organization_id` = carteira)

| Tabela | Campos principais |
|---|---|
| `finance_wallets` | kind (`pessoal`/`negocio`), name, is_mei, das_day, currency (BRL) |
| `finance_accounts` | kind (`corrente`, `poupanca`, `dinheiro`, `cartao`, `vale`), name, institution, opening_balance_cents, closing_day, due_day, limit_cents, archived_at |
| `finance_categories` | parent_id, kind (`gasto`/`recebimento`), name, icon, color, system_key (lista pronta), hidden_at |
| `finance_transactions` | account_id, kind (`gasto`, `recebimento`, `transferencia`), amount_cents (sempre positivo), date, time, description, category_id, notes (criptografado), status (`previsto`/`efetivado`), invoice_month (fatura do cartão a que pertence), series_id + installment/installments, transfer_pair_id, partner_id + partner_kind (`pro_labore`, `retirada`, `distribuicao`, `aporte`), linked_transaction_id (par na carteira pessoal do sócio), statement_line_id, source (`manual`, `whatsapp`, `extrato`, `planilha`, `socio`), created_by, deleted_at |
| `finance_statement_imports` | account_id, label, format (ofx/csv/xlsx/pdf), file_name, column mapping, undone_at (igual ao Studio) |
| `finance_statement_lines` | import_id, date, time, description, amount_cents, external_id, dedupe_key, status (`pending`, `created`, `matched`, `split`, `ignored`), split_details, transaction_id, hidden_at (igual ao Studio) |
| `finance_category_rules` | pattern (descrição normalizada, sem números e datas), category_id, origin (`pessoa`/`raposa`), hits |
| `finance_budgets` | category_id, amount_cents, month (nulo = todo mês) |
| `finance_partners` | user_id (nulo se o sócio não usa o sistema), name, share_bp (parte em centésimos de %), active |
| `finance_whatsapp_links` | user_id, phone, verified_at, default_wallet_id, default_account_id |

Notas:

- **Fatura sem tabela própria.** A fatura do cartão é calculada: lançamentos do cartão com o mesmo `invoice_month`. `invoice_month` sai do dia de fechamento quando o lançamento é criado. Pagar a fatura = transferência da conta corrente para o cartão.
- **Parcelado:** uma compra em 10x gera 10 lançamentos `previsto` com o mesmo `series_id`, um em cada fatura. "Comprometido nos próximos meses" = soma dos previstos.
- **Dinheiro em centavos** (inteiro), como no Commerce.
- Migrations aditivas, PostgreSQL e SQLite (o histórico do Commerce mostrou bugs só no Postgres: testar `migrate` no Postgres local antes do deploy).

## 5. Importação (cópia do Studio)

Fluxo igual ao que já funciona no Studio, trocando "cliente/cobrança" por "categoria/conta":

1. **Enviar o arquivo** escolhendo a conta (ou o cartão). OFX lê direto; CSV/XLSX/PDF pedem as colunas uma vez (data, descrição, valor, "saída/débito" opcional, inverter sinal) e **guardam o mapeamento por conta** para a próxima vez.
2. **Revisar:** cada linha mostra a categoria sugerida (regra → raposa) e, quando existe, "**Já está lançado?**" (o gasto que veio do WhatsApp ou foi digitado: mesmo valor, até 5 dias, palavras em comum) com "É o mesmo — conciliar".
3. **Lançar em lote** o que estiver com categoria; **dividir** uma linha (mercado + farmácia na mesma compra); **ignorar**; **isso é pessoal / isso é do negócio** (move para a outra carteira da mesma pessoa).
4. **Desfazer** a importação apaga só as linhas pendentes e marca `undone_at`; reenviar o mesmo arquivo não duplica (`dedupe_key` + mesma data/valor/descrição + horário, como no Studio).
5. **Planilha antiga** (controle feito à mão ou exportado de outro app): mapeamento de colunas com sinônimos, prévia, executar, desfazer (o `FinancialImporter` do Studio).

Ao corrigir a categoria de uma linha, a pessoa escolhe "sempre que aparecer *IFOOD*" → vira `CategoryRule`.

**Testes:** os casos de `FinancialStatementImportTest`, `PdfImportTest`, `StatementAlreadyPaidMatchTest` e `FinancialImportReliabilityTest` do Studio são trazidos e adaptados (mesmos arquivos de exemplo: PicPay agrupado por dia, crédito/débito separados, traços de PDF, linhas ilegíveis avisadas).

## 6. Categorização

1. **Regra da carteira** (`finance_category_rules`), pela descrição normalizada.
2. **Lista pronta da Foxy** (`IFOOD`, `UBER`, `NETFLIX`, `DROGASIL`... → categoria), em config, sem IA.
3. **Raposa em lote**: o que sobrou vai num pedido só (até ~50 descrições, sem valores nem nomes de pessoas), modelo mais barato; a resposta vira sugestão (a pessoa confirma) e, ao confirmar, regra `origin=raposa`.

## 7. Sócios

- `finance_partners` com a parte de cada um (somando 100%). O dono da carteira do negócio cadastra; o sócio pode ser convidado (membership) ou só um nome.
- **Lucro do mês** = recebimentos − gastos do negócio (sem contar os movimentos de sócio). **Quanto cabe a cada um** = lucro × parte.
- **Pró-labore, retirada, distribuição e aporte** são lançamentos com `partner_id` + `partner_kind`. **Acerto entre sócios** = o que cada um tirou − o que cabia a ele; a tela mostra "Fulano tirou R$ 400 a mais" e o botão "acertar" registra a transferência.
- **Retirada → carteira pessoal:** se o sócio tem login e aceitou uma vez ("lançar minhas retiradas na minha carteira pessoal"), `TransactionService` cria o recebimento na carteira pessoal dele e liga os dois por `linked_transaction_id`. Editar ou apagar um atualiza o outro. A carteira do negócio nunca lê a pessoal (só escreve esse par).
- **MEI:** lembrete do DAS no dia escolhido (lançamento previsto todo mês); total recebido no ano para a declaração anual.

## 8. WhatsApp

- **Número da Foxy, uma instância** (`foxy_financas`), D-F5. Webhook de entrada novo em `Platform\Messaging\Inbound` (token no endereço + verificação da instância; throttle).
- **Vincular:** em "Meu perfil", a pessoa informa o número; o sistema manda um código pelo WhatsApp; ela digita o código na tela. Número não vinculado que manda mensagem recebe só "Crie sua conta em financas.foxy.net.br" (uma vez por dia).
- **Lançar:** "gastei 42,90 no mercado no débito" → raposa extrai valor, descrição, conta e data (texto curto, modelo barato) → `TransactionService` lança com `source=whatsapp` → resposta "✅ R$ 42,90 · Mercado · Conta Nubank — responda *corrigir*". Foto do cupom: mesma coisa lendo a imagem (custa mais créditos; avisado).
- Mais de uma carteira: vai para a padrão; "no negócio" no texto muda a carteira.
- **Resumo semanal** opcional (domingo, por código + texto curto da raposa).

## 9. Raposa

`FinanceHost` declara o manual do sistema e as ferramentas só de leitura (`resumo_periodo`, `gastos_por`, `comparar_periodos`, `assinaturas_detectadas`, `faturas_futuras`, `orcamento_status`, `socios_mes`). Executadas com a carteira escolhida da pessoa logada. Os números vêm de `Insights` (as mesmas contas da tela); a raposa só explica. Créditos por plano (docs/07): Grátis 30 perguntas/mês, Pessoal e Compartilhado mais (valor na fatia 10, pelo custo medido).

## 10. Segurança e LGPD

- Toda consulta filtrada pela carteira (`BelongsToOrganization`); cada controller confere a membership; testes de "outra carteira não vê".
- Papéis: `dono` (tudo, inclusive sócios e plano), `membro` (lança e vê), `leitura` (só vê). Sem papéis customizados.
- `notes` criptografado (cast `encrypted`). **Descrição não**, porque busca, regras e "gastos por lugar" dependem dela; a proteção é o acesso ao banco e aos backups (já restritos).
- Para a raposa vai só o necessário à pergunta; para o Foxy Admin, só contagens e plano (sem valores).
- Exportar tudo (planilha + PDF) e apagar a carteira (com confirmação por senha); apagar a conta apaga as carteiras de que a pessoa é a única dona.
- Nunca pedir senha de banco.

## 11. Ordem de implementação (cada fatia com testes e commit)

| # | Fatia | Entrega |
|---|---|---|
| 0 | Recorte na Platform | `Sheet`, `PdfTable`, `Export`, `Csv` sobem para a Platform; Commerce igual (todos os testes verdes) |
| 1 | Base | Endereço `financas.foxy.net.br`, login/cadastro (fechado por `FOXY_FINANCAS_SIGNUP_OPEN`), carteira pessoal criada no cadastro, criar carteira do negócio, troca de carteira, convites e papéis, ProductAccount no Admin |
| 2 | Contas e lançamentos | Contas/cartões, categorias prontas, lançar em 3 toques, parcelado, repetição, transferência, editar/excluir, lista com filtros, PDF e planilha |
| 3 | Importação | Cópia do Studio: OFX/CSV/XLSX/PDF, mapeamento por conta, revisar, conciliar, lote, dividir, ignorar, desfazer, planilha antiga |
| 4 | Categorização | Regras, lista pronta, "sempre que aparecer", sugestão da raposa em lote |
| 5 | Cartão | Fatura atual e futuras, comprometido nos próximos meses, pagar fatura, aviso de vencimento |
| 6 | Painéis e orçamento | Início (entrou, saiu, sobra), top categorias e lugares, 6/12 meses, "o que mudou", calendário, orçamento com avisos; todos exportáveis |
| 7 | Sócios e MEI | Partes, pró-labore/retirada/aporte/distribuição, lucro do mês, acerto, retirada → carteira pessoal, "isso é pessoal", DAS |
| 8 | Raposa | FinanceHost/FinanceFox, ferramentas, assinaturas detectadas, explicação do mês |
| 9 | WhatsApp | Webhook de entrada na Platform, vínculo por código, lançar por texto e foto, resposta, resumo semanal |
| 10 | Planos | Grátis/Pessoal/Compartilhado no Stripe, limites, créditos da raposa, só leitura quando o pagamento vence |
| 11 | Privacidade | Exportar tudo, apagar carteira e conta |

Depois: vitrine (card do produto em foxy.net.br), guia em PDF com telas reais e deploy (passos do responsável no fim: DNS de `financas.foxy.net.br`, certificado, instância `foxy_financas` na Evolution, preços no Stripe).

## 12. Decisões para aprovação

- **A-F1** — Módulo `app/Finance` no app foxy2, endereço `financas.foxy.net.br`, carteira = Organization.
- **A-F2** — Importação copiada do Studio com os testes dele; nenhuma integração externa.
- **A-F3** — Fatura do cartão calculada (sem tabela), pagamento = transferência.
- **A-F4** — Retirada do sócio entra sozinha na carteira pessoal dele, só se ele aceitar uma vez.
- **A-F5** — WhatsApp: número vinculado por código; número desconhecido recebe só o convite.
- **A-F6** — Descrição sem criptografia (para busca e regras), observações criptografadas.
- **A-F7** — Ordem das fatias da seção 11.
- **D-F7 (produto, em aberto)** — Primeiros usuários e cadastro fechado no início.
