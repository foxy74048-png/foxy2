# Foxy Commerce — Definição de Produto e Domínio

> **Status:** Rascunho v0.1 — **hipóteses para validação**
> **Etapa:** 2 de 5 (Core conceitual ✔ → **Commerce** → Recorte da Platform → Arquitetura concreta → Implementação)
> **Atenção:** este documento foi escrito com hipóteses razoáveis para dar algo concreto para revisar. Tudo marcado com **[H]** é hipótese e precisa ser confirmado ou corrigido. As decisões pendentes estão na seção 9.

---

## 1. Problema

**[H]** Pequenos negócios brasileiros vendem por vários canais ao mesmo tempo — WhatsApp, Instagram, loja física, às vezes marketplaces — e controlam produtos, estoque e pedidos em planilhas, cadernos e conversas.

Consequências:

- estoque desatualizado (vende o que não tem, deixa de vender o que tem);
- pedidos perdidos ou esquecidos em conversas;
- nenhuma visão clara de quanto vendeu, o que vende mais, quem compra;
- muito tempo gasto em tarefas manuais (responder preço, mandar foto, conferir pagamento).

As ferramentas existentes tendem a cair em dois extremos:

- **plataformas de loja virtual** focadas no site, caras quando se somam apps e taxas, e que não resolvem a venda fora do site;
- **ERPs** completos, porém complexos demais para quem tem 1 a 10 pessoas.

## 2. Proposta de valor

**[H]** *"Um lugar só para organizar e vender: catálogo, estoque, pedidos e clientes — com uma loja online simples incluída."*

O centro do produto é a **operação de vendas** do negócio. A loja online é **um canal**, não o produto inteiro. Isso diferencia o Commerce de "mais um construtor de loja" e prepara a entrada de outros canais (WhatsApp, marketplaces, PDV) sem redesenho.

## 3. Público

**[H]** Público inicial:

- pequenos varejistas (roupas, cosméticos, acessórios, alimentos não perecíveis, artesanato, papelaria…);
- 1 a 10 pessoas na operação;
- já vendem (não estão começando do zero), mas de forma desorganizada;
- pouca familiaridade técnica;
- sensíveis a preço.

**Fora do público inicial:** grandes varejistas, operações com múltiplos CDs, serviços (agendamento), restaurantes/delivery de comida (domínio diferente).

## 4. Personas e papéis

| Persona | O que faz no Commerce | Papel no Core |
|---|---|---|
| **Dono(a) do negócio** | Tudo; vê números; configura a loja | `Owner` |
| **Gerente** | Catálogo, estoque, pedidos, relatórios | `commerce.manager` |
| **Atendente / vendedor** | Registra pedidos, consulta estoque, atende clientes | `commerce.attendant` |
| **Comprador** | Compra pela loja online | **Não é User do Core** — é `Customer` do Commerce |

## 5. Escopo

### 5.1 Mapa de módulos do Commerce (visão completa)

```text
Foxy Commerce
├── Catalog        produtos, variações (tamanho/cor), categorias, preços, imagens
├── Inventory      saldo, movimentações, ajustes, reservas
├── Customers      cadastro de compradores, histórico
├── Orders         pedidos de qualquer canal, ciclo de vida
├── Checkout       carrinho, cálculo de total, frete, cupom
├── Payments       cobrança do comprador (Pix, cartão, boleto) via provedor
├── Fulfillment    separação, envio, retirada, rastreio
├── Storefront     loja online pública
├── Channels       WhatsApp, Instagram, marketplaces, PDV       (futuro)
├── Fiscal         NF-e / NFC-e                                  (futuro)
└── Insights       relatórios e indicadores
```

### 5.2 Proposta de MVP

Critério: **o mínimo para um lojista real organizar suas vendas e receber pedidos online, e aceitar pagar por isso.**

| Módulo | No MVP | Fora do MVP (depois) |
|---|---|---|
| **Catalog** | Produtos, variações, categorias, preço, imagens | Kits, preços por canal, importação em massa |
| **Inventory** | Estoque por variação, um local, ajuste manual, baixa automática no pedido, alerta de estoque baixo | Múltiplos locais, lotes/validade, inventário cíclico |
| **Customers** | Cadastro automático a partir dos pedidos + manual | Segmentação, fidelidade |
| **Orders** | Pedido manual (venda no WhatsApp/balcão) + pedido da loja; ciclo de vida completo | Edição avançada, devoluções/trocas parciais |
| **Checkout** | Carrinho, checkout como convidado, frete simples | Cupons avançados, carrinho abandonado |
| **Payments** | Pix + cartão por **um** provedor | Boleto, múltiplos provedores, split |
| **Fulfillment** | Retirada, entrega local (taxa fixa/por região), envio com valor fixo; status e código de rastreio manual | Cotação automática (Correios/transportadoras), etiquetas |
| **Storefront** | Loja simples, responsiva, com tema único configurável (logo, cores) em subdomínio | Domínio próprio, temas, editor visual, SEO avançado |
| **Insights** | Painel: vendas do período, ticket médio, mais vendidos, estoque baixo | Relatórios exportáveis, comparativos |
| **Channels / Fiscal** | — | Marketplaces, catálogo WhatsApp, PDV, NF-e |
| **Foxy AI** | **Uma** funcionalidade pequena (ver D7) | Assistente que executa ações |

### 5.3 Fluxo principal do MVP

```text
Lojista cadastra produtos e estoque
        ↓
Publica a loja (subdomínio)
        ↓
Comprador compra (convidado) → paga com Pix/cartão
        ↓                                    ↑
Ou: atendente registra pedido manual ────────┘ (pagamento registrado manualmente ou link de pagamento)
        ↓
Estoque baixa automaticamente
        ↓
Lojista recebe notificação → separa → envia/entrega → marca como concluído
        ↓
Comprador recebe e-mails de status
        ↓
Painel mostra vendas e estoque
```

## 6. Modelo de domínio (conceitual)

### 6.1 Conceitos principais

```text
Product ──< Variant ──1 StockLevel ──< StockMovement
   │
   └── Category, Images(file_id)

Customer ──< Order ──< OrderItem ──> Variant (com snapshot de nome/preço)
               │
               ├── Payment(s)
               ├── Fulfillment
               └── channel: storefront | manual
```

Regras conceituais:

- **Variant é a unidade vendável e estocável** (camiseta P azul). Produto sem variações tem uma variante implícita.
- **OrderItem guarda um snapshot** de nome, SKU e preço no momento da venda — alterar o catálogo não muda pedidos antigos.
- **Estoque só muda por StockMovement** (entrada, ajuste, venda, cancelamento). O saldo é consequência das movimentações, o que dá rastreabilidade.
- **Todo** conceito acima pertence a uma Organization.

### 6.2 Ciclo de vida do pedido

```text
               ┌──────────── canceled ◄──────────┐
               │                                 │
placed ──► awaiting_payment ──► paid ──► in_fulfillment ──► shipped/ready_for_pickup ──► completed
               │
               └─► payment_failed ──► (nova tentativa ou expira → canceled)
```

- Estoque é **reservado** em `placed` e **baixado** em `paid` (ou baixado direto em pedidos manuais já pagos). Reserva expira se o pagamento não ocorrer. **[H]** — alternativa mais simples: baixar no `placed` e devolver no cancelamento. Decidir na etapa 4.

### 6.3 Eventos de integração candidatos

| Evento | Quem reage (exemplos) |
|---|---|
| `OrderPlaced` | Inventory (reserva), Notifications (avisar lojista) |
| `OrderPaid` | Inventory (baixa), Fulfillment, Notifications (comprador) |
| `OrderCanceled` | Inventory (devolve), Payments (estorno se pago), Notifications |
| `OrderShipped` | Notifications (comprador com rastreio) |
| `PaymentFailed` | Orders, Notifications |
| `StockLow` | Notifications (lojista) |
| `ProductUpdated` | Storefront (cache), futuros canais |

Os eventos são publicados pelo Commerce. Alguns são consumidos dentro do próprio Commerce; o fato de serem eventos de integração prepara futuros canais e produtos sem acoplamento.

## 7. Capacidades do Core que o Commerce usa

Este é o insumo principal da etapa 3. Níveis conforme `01-core-conceitual.md` seção 11 (N0 contrato, N1 mínimo, N2 completo).

| Capacidade | Uso pelo Commerce | Nível proposto | Justificativa |
|---|---|---|---|
| **Identity** | Login de lojistas e equipe | **N1** | Indispensável. E-mail + senha, verificação de e-mail, recuperação. Login social/MFA depois. |
| **Organization** | A loja é a organização | **N1** | Indispensável. |
| **Membership** | Dono convida equipe | **N1** | Convite por e-mail + papéis fixos. |
| **Authorization** | Owner / manager / attendant | **N1** | Papéis padrão, permissões `commerce.*`. Sem papéis customizados. |
| **Execution Context** | Toda operação | **N1** | Base do isolamento de tenant — não pode ser adiado. |
| **Product Catalog** | Registrar `commerce` e suas features | **N0** | Declarado em código/config; sem tela de gestão. |
| **Plans** | — | **N0** ou **N1** | Depende de D5. |
| **Subscription** | Trial e status da assinatura | **N0** ou **N1** | Depende de D5. |
| **Billing** | Cobrar o lojista | **N0** ou **N1** | Depende de D5. |
| **Entitlements** | `has_feature`, limites (ex.: nº de produtos, uso de IA) | **N0→N1** | Commerce chama desde o início; implementação simples por trás. |
| **Events** | Eventos da seção 6.3 | **N1** | Publicação/assinatura interna, confiável. |
| **Files** | Imagens de produto, logo da loja | **N1** | Indispensável para catálogo e vitrine. |
| **Notifications** | E-mails transacionais (lojista e comprador) | **N1** | Apenas e-mail. WhatsApp depois. |
| **Audit** | Ações relevantes (cancelamentos, ajustes de estoque, mudanças de preço, equipe) | **N1** | Simples, somente-inclusão. |
| **APIs** | API de aplicação para o frontend | **N1** | API pública e API Clients em **N0** (boundary só). |
| **Webhooks de saída** | — | Fora | Sem demanda ainda. |
| **Foxy AI** | Ver D7 | **N0** ou **N1** | Validar o boundary com um caso pequeno e útil. |
| **Backoffice Foxy** | Suporte acessar loja de cliente com auditoria | **N1 mínimo** | Necessário para operar clientes reais. |

**Observação:** o Commerce precisa de **Payments próprio** (comprador → lojista). Isso é domínio do Commerce, não Billing do Core (ver Core, seção 6.4).

## 8. O que o Commerce **não** faz (limites)

- Não implementa login, usuários, organizações ou papéis — usa o Core.
- Não decide acesso por plano — consulta Entitlements.
- Não envia e-mails diretamente — pede ao Notifications.
- Não guarda arquivos diretamente — usa Files e guarda `file_id`.
- Não fala com provedores de IA — registra tools na Foxy AI.
- Não emite nota fiscal no MVP.

## 9. Decisões pendentes (preciso de você)

| # | Decisão | Opções / recomendação |
|---|---|---|
| **D1** | O problema e a proposta de valor (seções 1–2) refletem o que você quer? | Confirmar ou corrigir. É a decisão mais importante. |
| **D2** | Existe um primeiro cliente real (ou 2–3) para usar o MVP? Que tipo de negócio? | Ideal ter ao menos um. Orienta todas as prioridades do MVP. |
| **D3** | A lista do MVP (5.2) está certa? Algo indispensável faltando ou algo sobrando? | Ex.: NF-e é obrigatória para seu público desde o início? |
| **D4** | Provedor de pagamento do comprador | Recomendação: um provedor brasileiro com Pix + cartão e boa API (ex.: Mercado Pago, Pagar.me, Asaas, Stripe BR). Escolha concreta na etapa 4. |
| **D5** | A Foxy cobra o lojista desde o primeiro cliente? | **a)** Cobrança manual no início (Plans/Subscription/Billing em N0) — **recomendado** para validar rápido. **b)** Assinatura automatizada desde o dia 1. |
| **D6** | Sistema antigo da Foxy | ✔ **Decidido (2026-10-13):** o sistema atual segue separado e independente, sem migração para o Commerce. Daqui para frente: um Core e vários produtos separados sobre ele. |
| **D7** | Primeira funcionalidade de IA | Recomendação: **gerar descrição de produto a partir de nome + foto**. Útil, barata, sem risco (não executa ações), valida o boundary da Foxy AI e a medição de uso. |
| **D8** | Comprador com login (Q2 do Core) | Recomendação: **não no MVP** — checkout como convidado. |
| **D9** | Equipe e habilidades | Quem vai desenvolver? Quais linguagens/frameworks você domina? (Insumo para a stack na etapa 4.) |

> Insumos já levantados no sistema legado para D4, D5, D6 e D9: ver [legado-insumos.md](legado-insumos.md).

## 10. Próxima etapa

Com as decisões D1–D9, este documento vira v1.0 e passamos à **etapa 3 — Recorte da Platform**: a tabela da seção 7 vira o escopo fechado do Core a ser implementado junto com o Commerce.
