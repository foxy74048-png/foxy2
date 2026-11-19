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
| 4 | Categorização | Regras, lista pronta de lojas, "sempre que aparecer", aprender com a correção (a sugestão da raposa em lote foi para a fatia 8: a API da Foxy AI ainda não tem tarefa avulsa) |
| 5 | Cartão | Fatura atual e futuras, comprometido nos próximos meses, pagar fatura, aviso de vencimento |
| 6 | Painéis, orçamento e indicadores | Início (entrou, saiu, sobra), top categorias e lugares, 6/12 meses, "o que mudou", calendário, orçamento com avisos; **indicadores para economizar** (taxa de poupança, "no ritmo atual o mês fecha em", o que mais subiu, gastinhos, assinaturas e custo por ano, reserva de emergência em meses, "se cortar pela metade"); todos exportáveis |
| 6b | Metas de guardar | Meta com valor e prazo ligada a uma conta ou a transferências marcadas; quanto falta e quanto guardar por mês |
| 6d | Despesas em grupo (pedido em 2026-11-19) | Grupo (viagem, república, festa) com as pessoas; cada despesa com quem pagou e entre quem divide (igual, só alguns ou valores); saldo de cada um (gastou / pagou / recebe ou deve); a lista mais curta de pagamentos para zerar; registrar pagamento; link só de leitura para o grupo (token secreto, sem login) e resumo para o WhatsApp; "Levar para Quem me deve" no fim |
| 6c | Quem me deve | Pessoas (`finance_contacts`) e movimentos (`finance_person_entries`: emprestei, peguei emprestado, dividi a conta, pagou, objeto emprestado/devolvido); dinheiro que sai de uma conta vira transferência para a conta interna "A receber de amigos" (kind `a_receber`, fora do "saiu"); dividir a conta = gasto só da sua parte + o resto a receber; saldo por pessoa; lembrete pelo WhatsApp com `wa.me` (manual) |
| 7 | Sócios e MEI | Partes, pró-labore/retirada/aporte/distribuição, lucro do mês, acerto, retirada → carteira pessoal, "isso é pessoal", DAS |
| 8 | Raposa | FinanceHost/FinanceFox, ferramentas, explicação do mês e dicas a partir dos indicadores; **tarefa avulsa na API da Foxy AI** (categorizar em lote, ler mensagem do WhatsApp), com testes no FakeProvider |
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

## 13. Andamento

- **Fatia 0 (2026-11-18):** `Sheet` e `PdfTable` em `App\Platform\Imports`, `Export` e `Csv` em `App\Platform\Exports`, `Money` em `App\Platform\Support`; PDF em `resources/views/platform/export-pdf.blade.php` com o nome do produto (`$product`) e da conta (`accountName()`). No Commerce ficaram classes com os mesmos nomes que estendem as da Platform (`Commerce\Insights\Export` diz "Foxy Commerce" e o nome da loja), então nada mudou por lá. Teste: `tests/Feature/Platform/SharedImportExportTest`.
- **Fatia 1 (2026-11-18):** módulo `app/Finance` com rotas em `routes/finance.php` presas a `config('foxy.hosts.financas')` (`FOXY_FINANCAS_HOST`, padrão `financas.foxy.net.br`), nomes `finance.*`. Cadastro fechado por `FOXY_FINANCAS_SIGNUP_OPEN` (D-F7: fechado até o lançamento); com ele fechado, quem já é dono de uma carteira (ou é da equipe Foxy) ainda abre outra, e convites funcionam sempre. O cadastro cria a carteira **Pessoal** e, se marcado, a do **negócio** (nome + MEI). Carteira = `Organization` da integração `financas` + `finance_wallets` (kind `pessoal`/`negocio`, `is_mei`, `das_day`); `OpenWallet` abre, `ResolveWallet` (`finance.wallet`) escolhe (sessão → única → a pessoal de que é dona) e monta o contexto; troca de carteira direto no menu. Papéis em `config/finance.php`: `owner` (tudo), `member` (lança, importa, vê relatórios), `viewer` (só vê); `finance.can:` + `Finance\Access`. Pessoas na carteira (convidar, papel, suspender, retirar, nunca sem dono; até `finance.max_people` = 4, provisório até os planos), convite só vale no produto dele (o Commerce passou a recusar convite de carteira e vice-versa). Conta no Foxy Admin (`Finance\Accounts\AccountSync`, status novo `free` = "Plano grátis", sem valores). E-mails de confirmar e-mail e de nova senha agora em `Platform\Identity\ProductLogin`: o link e o nome do produto seguem o endereço em que a pessoa está (`config('foxy.logins')`). Teste: `tests/Feature/Finance/IdentityAndWalletsTest` (19).
- **Para pôr no ar (responsável, no fim):** DNS de `financas.foxy.net.br` → mesmo servidor; incluir no `server_name` do nginx da foxy2 e no certificado; `php artisan migrate --force` e `config:cache`. Até liberar o cadastro, a primeira carteira é aberta pela equipe Foxy (login `is_staff`) ou por convite.
- **Fatia 2 (2026-11-18):** `finance_accounts` (corrente, poupança, dinheiro, vale, cartão com fechamento/vencimento/limite; arquivar em vez de excluir quando tem lançamento), `finance_categories` (2 níveis; lista pronta pessoal ou de negócio em `Categories\DefaultCategories`, com `system_key`, criada com a carteira; criar, renomear, mudar de grupo, esconder, juntar) e `finance_transactions`. Mudança em relação à seção 4: transferência é **uma linha** com `to_account_id` (sem `transfer_pair_id`), e `series_kind` (`parcelas`/`mensal`) diz o tipo da série; `partner_*`, `statement_line_id` e `linked_transaction_id` entram nas fatias 3 e 7. `TransactionService` é a única porta: uma vez, parcelado (divide, resto dos centavos na 1ª; no cartão uma parcela por fatura), todo mês (até 36; os futuros ficam previstos), editar/apagar "este e os próximos", confirmar o previsto, saldo de hoje por conta (inicial + feito até hoje; transferência sai de uma e entra na outra). Cartão: lançamento sempre "feito"; `Account::invoiceMonthFor()` = mês de vencimento da fatura (compra no dia do fechamento já vai para a próxima). Data gravada como `Y-m-d` (o cast `date` grava hora no SQLite e quebrava `date <= dia`). Observação com cast `encrypted`. Telas: Lançamentos (lançar em 3 toques, mês com setas, Entrou/Saiu/Sobrou/Previsto, filtros sempre abertos, PDF e planilha), Contas e cartões (saldo, fatura atual), Categorias, Início com o mês, saldo, "onde mais gastou" (grupo soma as subcategorias) e próximos 7 dias. Papel `member` ganhou `finance.accounts.manage`. `financas:contas` de hora em hora leva contagens e a última atividade ao Foxy Admin. **Correção na Platform:** `Money::toCents('1.200')` agora é mil e duzentos (antes R$ 1,20; valia também para o Commerce). Teste: `tests/Feature/Finance/TransactionsTest` (19).
- **Fatia 3 (2026-11-19):** importação em "Importar extrato" (`/app/extratos`, permissão `finance.imports.manage`). Copiado do Studio: `Finance\Statements\StatementParser` (igual; única mudança: coluna opcional de categoria em `csvRows`), `Platform\Imports\RawTable` (leitor de tabela crua CSV/XLSX/PDF do `SpreadsheetReader`; fica ao lado da `Sheet`, que devolve linhas com cabeçalho), a regra de repetidos do `StatementImporter` (FITID do OFX; data+hora+valor+descrição+ordem; "já existe" com horário e palavra em comum) — **por conta**, no lugar do "nome da origem" do Studio — e os testes (OFX SGML/XML, traços no lugar do menos, débito em coluna própria, sufixo D/C, Excel com data numérica, PicPay em PDF com nome em 2 linhas, horário que separa Pix iguais, linhas ilegíveis avisadas). Tabelas `finance_statement_imports` e `finance_statement_lines` (valor com sinal, em centavos), `finance_transactions.statement_line_id`, `finance_accounts.statement_mapping` (as colunas ficam guardadas por conta; fatura de cartão já vem com "inverter o sinal"). Revisão (`StatementResolver`, tudo pelo `TransactionService`, linha travada contra clique duplo): **lançar** com a categoria sugerida, **conciliar** com o que já estava lançado (digitado, WhatsApp ou previsto — o previsto vira feito com a data do extrato; mesma conta primeiro, depois palavras em comum, depois o dia mais próximo; um lançamento nunca é sugerido para duas linhas), **transferência** entre contas (pagar a fatura), **dividir** (a soma tem que fechar), **ignorar**, **desfazer** (lançado é apagado; conciliado só solta), **lote** (marcadas, ou "as que já têm categoria"; o que parece já lançado nunca entra), **possível repetido**, tirar da lista / trazer de volta, **desfazer a importação** (só as para revisar) ou **desfazer tudo** (também o lançado). Apagar em Lançamentos um lançamento que veio do extrato devolve a linha para revisar (dividida perde só a parte). **Planilha antiga** = o mesmo fluxo com a coluna de categoria (categoria com o nome dado, criada se não existir) — no lugar do `FinancialImporter` separado do Studio. Categoria sugerida sem IA (`CategoryGuesser`): coluna da planilha, ou a usada da última vez na mesma descrição (sem números/acentos). Telas: `finance/statements/index` (enviar sempre aberto, importações recentes, abas, ações à vista em cada linha) e `mapping`. Teste: `tests/Feature/Finance/StatementImportTest` (25) + `tests/Concerns/BuildsPdf|BuildsXlsx` (copiados do Studio).
- **Fatia 4 (2026-11-19):** `Finance\Categories\Categorizer` (substitui o `CategoryGuesser` da fatia 3): **regra da carteira** (`finance_category_rules`: padrão normalizado, tipo, categoria, origem, vezes usada; a mais longa vence; palavra inteira) → **a categoria da última vez** na mesma descrição sem números → **lista pronta de lojas** (`config/finance_merchants.php`, ~200 lojas e concessionárias → `system_key`; só sugere se a categoria existe e está visível na carteira). `finance_statement_lines.suggestion_origin` (regra, histórico, lista, planilha) aparece na tela. "Sempre que aparecer" ao lançar uma linha do extrato (marcado sozinho quando a pessoa troca a categoria; padrão sugerido = 2 primeiras palavras que identificam a loja, editável) e ao editar um lançamento (com "pôr nos parecidos sem categoria"); a regra nova já atualiza a sugestão das linhas para revisar. Lançar à mão sem categoria usa a automática e avisa. Regras na tela de Categorias (criar, trocar categoria, apagar; exporta junto). A categorização em lote pela raposa ficou para a fatia 8 (precisa de tarefa avulsa na API da Foxy AI). Teste: `tests/Feature/Finance/CategorizationTest` (10).
- **Fatia 5 (2026-11-19):** `Finance\Accounts\CardInvoices` calcula as faturas dos lançamentos (sem tabela, A-F3): gastos − estornos do cartão no `invoice_month`, pagamentos = transferências para o cartão com o mesmo `invoice_month`; situação aberta / futura / fechada / paga em parte / paga / vencida; `outstanding` (tudo o que falta pagar, inclusive parcelas — base do limite disponível) e `committed` (parcelas nas faturas depois da aberta). `Account::closingDate()`, `dueDate()`, `paidInvoiceFor()` (pagamento paga a última fatura que fechou; editar a data não muda a fatura paga). Telas "Faturas do cartão" (`/app/cartoes`: falta pagar, limite disponível com barra, comprometido, faturas com fecha/vence/situação) e a fatura (`/app/cartoes/{cartão}?fatura=AAAA-MM`: itens, pagamentos, "Pagar esta fatura" = transferência ligada à fatura, PDF e planilha). Início avisa a fatura fechada a pagar (vencida em vermelho). **Correção de regra da fatia 2:** no cartão só a **parcela** nasce feita; a repetição (assinatura) e a compra com data futura ficam previstas e viram feitas sozinhas quando a data chega (`TransactionService::confirmDueCardCharges`, ao abrir faturas e Início, e `financas:cartoes` todo dia 00:10) — assim a assinatura futura não come o limite. Teste: `tests/Feature/Finance/CardInvoicesTest` (7).
- **Fatia 6 (2026-11-19):** `Finance\Insights\Indicators` (por mês, 12 meses carregados uma vez; só feito, sem transferências): resumo (entrou, saiu, sobrou, % da renda guardado e a média dos 3 meses antes), ritmo do mês corrente (gasto por dia × dias do mês, e o já previsto), categorias por grupo com a média dos 3 meses antes, **o que mais subiu** (≥ R$ 30 e ≥ 20%; só com histórico — `hasHistory()`), onde mais gastou por lugar (`Categorizer::defaultPattern`), **gastinhos** (até R$ 30: no mês e por ano no ritmo dos últimos 3 meses), **assinaturas** (lançadas "todo mês" ou o mesmo lugar uma vez por mês com valor ±15% em 3 dos últimos 4 meses; fora do essencial `Indicators::ESSENTIAL`), **reserva de emergência** (saldo das contas poupança ÷ gasto médio = meses), **"se cortar pela metade"** (3 grupos fora do essencial onde mais gasta × ½ × 12), evolução mês a mês e o **orçamento** (`finance_budgets`: limite por categoria — o grupo inclui as de dentro —, todo mês ou só num mês, que vence; aviso a partir de 80% e ao estourar). Telas: "Onde vai o dinheiro" (`/app/painel`, permissão `finance.reports.view`, tudo exportável numa planilha/PDF com seções) e "Orçamento" (`/app/orcamento`, com sugestão de limite 10% abaixo do que gasta). Início: % guardado, "no ritmo atual", orçamento pedindo atenção e link para economizar. Gráficos: `platform.charts.assets` e `platform.charts.hbars` (vieram do Commerce, que passou a usá-los de lá). Teste: `tests/Feature/Finance/IndicatorsTest` (7).
- **Fatia 6b (2026-11-19):** metas de guardar (`/app/metas`). `finance_goals` (nome, quanto juntar, prazo opcional, conta onde fica — opcional, não cartão —, quanto já tinha, alcançada, histórico) e `finance_goal_entries` ("guardei" +, "tirei" −; com conta na meta e conta de origem/destino, a entrada cria uma **transferência** pelo `TransactionService` e guarda o `transaction_id`; apagar a entrada apaga a transferência). `Goal::progress()`: juntado, falta, %, meses que faltam (contando o atual), **quanto guardar por mês**, **no ritmo** (linha reta do dia da criação até o prazo), prazo passou. Alcançada sozinha ao chegar no valor (e volta se tirar). Atalho "reserva de emergência" = 6 × o gasto médio dos 3 meses antes (arredondado para cima em R$ 100). Início mostra até 3 metas. Exporta. (`Goal::savedCents()`: o nome `saved` é evento do Eloquent.) Teste: `tests/Feature/Finance/GoalsTest` (5).
- **Fatia 6c (2026-11-19):** "Quem me deve" (`/app/quem-me-deve`). `finance_contacts` (nome, WhatsApp opcional; mesmo nome sem acento/maiúscula = mesma pessoa) e `finance_person_entries` (valor com sinal: + a pessoa te deve, − você deve; tipos emprestei, peguei, devolveu, paguei, dividi, objeto; prazo combinado; objeto com "devolvido em"). `Finance\Debts\DebtLedger` é a única porta: com conta escolhida, o dinheiro vira **transferência** com a conta interna **"Amigos e família"** (`Account::PEOPLE`, criada sozinha; `scopeActive` a tira de formulários, Contas e saldos; `scopeOpen` deixa o `TransactionService` aceitar) — emprestar não é gasto, receber não é renda. Não deixa devolver/pagar mais do que o saldo. **Dividir a conta:** gasto só da sua parte (com categoria) + transferência do resto para "Amigos e família" + uma entrada por pessoa (`group_id`); no cartão o total entra na fatura; apagar apaga a divisão inteira. **Lembrete:** link `wa.me` com o texto pronto (abre o WhatsApp da própria pessoa; nada automático). Pessoa quite pode sair da lista. Início mostra "te devem / você deve". Exporta. Teste: `tests/Feature/Finance/DebtsTest` (8).
- **Fatia 7 (2026-11-19):** sócios e MEI (`/app/socios`, só na carteira do negócio). `finance_partners` (nome, parte em centésimos de %, login opcional, conta da carteira pessoal onde as retiradas entram — escolhida **só pelo próprio sócio**), `finance_partner_settlements` (acerto por fora do caixa) e, nos lançamentos, `partner_id`, `partner_kind` (pró-labore, retirada, distribuição = gasto; aporte = recebimento) e `linked_transaction_id`. `Finance\Partners\PartnerService`: **lucro** = entrou − saiu **sem** movimentos de sócio; **cabe** = lucro (se positivo) × parte; **tirou** = pró-labore + retirada + distribuição − aportes ± acertos; diferença e **sugestão de acerto** (quem tirou a mais paga a quem tirou a menos). **Retirada na carteira pessoal:** recebimento na conta escolhida, categoria "Pró-labore e retiradas", ligado nos dois sentidos; editar no negócio atualiza lá; apagar no negócio apaga lá; apagar lá só desfaz a ligação. **MEI:** faturamento do ano (sem aportes) contra o limite de R$ 81.000 e lembrete do DAS (12 meses previstos no dia do DAS, categoria DAS). **Extrato:** "É gasto pessoal do sócio" vira retirada dele (não conta como gasto do negócio). Indicadores (fatia 6) ignoram movimentos de sócio. Permissões: cadastrar sócio = dono (`finance.wallet.manage`); movimentos = `finance.transactions.manage`. Teste: `tests/Feature/Finance/PartnersTest` (7).
- **Fatia 6d (2026-11-19):** despesas em grupo (`/app/grupos`). `finance_groups` (nome, token secreto do link, encerrado, levado para "Quem me deve"), `finance_group_members` ("Você" + os outros, ligados a um contato de "Quem me deve" quando dá), `finance_group_expenses` (quem pagou, igual ou valores) com `finance_group_shares`, e `finance_group_payments`. Fica **fora** dos lançamentos da carteira. `Finance\Groups\GroupLedger`: divisão igual sem perder centavo (sobra vai para os primeiros), valores que têm que fechar; saldo = pagou − parte + passou − recebeu; **quem paga quem** com o menor número de pagamentos (maior devedor → maior credor); "Já pagou" registra o pagamento; **"Encerrar e levar para Quem me deve"** cria `PersonEntry` tipo `grupo` só dos acertos com você. Link público `/grupo/{token}` (sem login, só leitura, `noindex`, throttle; a página é montada no contexto da carteira; "Você" aparece como "Quem criou o grupo"); trocar o link invalida o antigo; resumo para o WhatsApp (`wa.me/?text=`) com o nome de quem criou. Teste: `tests/Feature/Finance/GroupsTest` (6).
- **Fatia 8 (2026-11-19):** a raposa (desenho na seção 14, R-1..R-4 aprovados). **Foxy AI:** tarefa avulsa `POST /api/v1/tasks` (escopo `ai.tasks`; `App\Ai\Tasks\TaskCatalog` + `TaskRunner`; `ModelRequest::$outputSchema` → `output_config.format` json_schema no `AnthropicProvider`; modo rápido, sem ferramentas; crédito, uso e auditoria sem conteúdo; até 30/min por cliente) e o plano `ia-30` (`finance.ai_plan`). **Finanças:** `Finance\Assistant\FinanceHost` (perfil `foxy-financas`, manual e as consultas `resumo_periodo`, `gastos_por`, `comparar_periodos`, `economizar`, `assinaturas`, `faturas_cartao`, `orcamento`, `metas`, `quem_me_deve`, `grupos` e `socios_mes` — esta só no negócio —, cada uma presa a uma permissão do papel; valores já formatados; sem transferências nem movimentos de sócio) e `FinanceFox` (chave própria `finance-ai-key`, plano por carteira uma vez por sessão, mensagens de erro para a pessoa). Janela da raposa em todas as telas (`finance/nav`), publicada por `php artisan financas:raposa`. **"Sugerir com a raposa"** no extrato (permissão `finance.imports.manage`): até 50 linhas sem categoria, só a descrição (sem CPF/CNPJ e números longos) e o tipo vão; só aceita categoria da carteira e do mesmo tipo; origem "sugerida pela raposa". **Dicas do mês** em "Onde vai o dinheiro": os fatos calculados (`FoxController::facts`, já com R$) viram 3 frases e uma sugestão, guardadas em `finance_ai_notes` (uma por mês; "Escrever de novo" refaz); frase com valor que não está nos fatos é descartada. Teste: `tests/Feature/Finance/FoxTest` (6) + `tests/Feature/Ai/TaskApiTest` (5).
- **Para pôr no ar (fatia 8, responsável):** `php artisan financas:raposa` depois do migrate (publica o perfil e guarda a chave); conferir o limite de gasto da conta Anthropic.

## 14. Fatia 8 — a raposa no Finanças (desenho para aprovação, 2026-11-19)

Hoje a API da Foxy AI só tem **conversa** (o assistente: pergunta → resposta, com ferramentas do sistema). O Finanças precisa de duas coisas:

1. **Conversa** (já existe, só falta o Finanças virar anfitrião): perguntar sobre os próprios dados.
2. **Tarefa avulsa** (nova na Foxy AI): um pedido só, sem conversa, com **resposta organizada** (JSON), para o sistema usar direto — categorizar em lote, ler a mensagem do WhatsApp (fatia 9) e escrever as dicas do mês.

### 14.1 Tarefa avulsa na Foxy AI (vale para todos os produtos)

- **Endereço:** `POST /api/v1/tasks` (cliente da API + organização, permissão nova `ai.tasks`), com `{ "task": "finance.categorize", "input": { ... } }` → `{ "output": { ... }, "usage": { ... } }`.
- **Só tarefas cadastradas na Foxy AI** (`App\Ai\Tasks\TaskCatalog`): cada uma com o texto de instrução (guardado e em cache, igual ao do assistente), o **formato da resposta** (esquema JSON, que o modelo é obrigado a seguir), o modelo (o mais barato, Haiku) e o limite de tamanho. O produto **não manda instrução livre** — evita abuso, vazamento e custo fora de controle.
- **Mesmas regras de crédito do assistente:** sem crédito, a tarefa não roda (e o sistema segue sem IA, só com as regras); o gasto entra no uso da organização e aparece no Foxy Admin (medidor novo `task`).
- **Testes** com o `FakeProvider`, como o resto da Foxy AI; nenhum teste chama a IA de verdade.

### 14.2 Tarefas do Finanças

| Tarefa | Entra | Sai | Onde aparece | Custo estimado |
|---|---|---|---|---|
| `finance.categorize` | até 50 descrições (só o texto; **sem valor, data, conta ou nome da carteira**) e a lista de categorias da carteira | para cada uma: a categoria ou "não sei" | botão **"Sugerir com a raposa"** no extrato, para as linhas sem sugestão. Vira sugestão (origem "raposa"); a pessoa confirma ao lançar, e aí pode virar regra | ~1 crédito por lote de 50 (≈ US$ 0,004) |
| `finance.tips` | os indicadores do mês **já calculados** (fatia 6: % guardado, o que subiu, gastinhos, assinaturas, reserva, "cortar pela metade") | 3 frases curtas e 1 sugestão | card **"A raposa sobre o seu mês"** em "Onde vai o dinheiro" e no Início | 1 crédito por mês por carteira (guardado; só refaz se a pessoa pedir) |
| `finance.parse_message` (fatia 9) | o texto da mensagem do WhatsApp e os nomes das contas | tipo, valor, descrição, conta, data | lançar pelo WhatsApp | ~0,2 crédito por mensagem |

A IA **nunca faz conta**: os números vêm do sistema, ela só escreve. O texto que ela devolve é conferido (valor que não está nos dados = descartado).

### 14.3 Conversa: o Finanças como anfitrião da raposa

`FinanceHost` + `FinanceFox`, do mesmo jeito que `CommerceHost`/`CommerceFox`: manual do sistema e **ferramentas só de leitura**, executadas com a carteira e as permissões de quem está logado:

`resumo_periodo` · `gastos_por` (categoria, lugar, conta) · `comparar_periodos` · `assinaturas` · `faturas_cartao` · `orcamento` · `metas` · `quem_me_deve` · `grupos` · `socios_mes` (só na carteira do negócio).

A janela da raposa aparece em todas as telas (como no Commerce), com sugestões do tipo "Quanto gastei com delivery este ano?", "Onde dá para economizar?", "Quem está me devendo?".

### 14.4 O que muda e o que não muda

- **Muda na Foxy AI** (módulo usado também pelo Studio e pelo Commerce): só **acréscimos** — rota nova, catálogo de tarefas, medidor novo. A conversa existente não muda; os testes atuais da Foxy AI continuam valendo.
- **Privacidade:** para a IA vai só o necessário de cada tarefa (descrições para categorizar; números agregados para as dicas). Nada de CPF, conta bancária ou nome da carteira. O registro guarda o uso (tokens, custo), não o conteúdo da tarefa.
- **Sem crédito ou sem a IA no ar:** tudo continua funcionando com as regras e a lista de lojas (fatia 4); o botão da raposa só some.
- **Passo do responsável (no fim):** nenhum novo — a chave e o limite de gasto da Anthropic já existem. Lembrar de **subir o limite mensal** (hoje US$ 5) antes de liberar para muita gente.

### 14.5 Decisões para aprovação

- **R-1** — Criar a tarefa avulsa na Foxy AI como acima (só tarefas cadastradas, resposta em JSON, mesmas regras de crédito).
- **R-2** — As três tarefas do Finanças (categorizar, dicas do mês, ler mensagem do WhatsApp), com o que entra e sai na tabela 14.2.
- **R-3** — A raposa de conversa com as ferramentas da seção 14.3.
- **R-4** — Créditos da raposa por plano (fatia 10): Grátis 30 perguntas/mês (as tarefas contam crédito também), Pessoal e Compartilhado com mais. Valores fechados na fatia 10, pelo custo medido.
