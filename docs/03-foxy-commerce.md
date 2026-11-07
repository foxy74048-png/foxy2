# Foxy Commerce — Definição de Produto e Domínio

> **Status:** v0.2 (2026-11-07). Retomado porque há lojistas interessados. Respostas do responsável já incorporadas; faltam as decisões da seção 10.
> **Ordem:** segundo produto, sobre o Core da foxy2 (mesmo app de `ai.foxy.net.br` e do Foxy Admin). Endereço reservado: `ecommerce.foxy.net.br`.
> **Mudança em relação à v0.1:** o produto deixou de ser "uma loja online com estoque" e passou a ser um **hub multicanal**: um cadastro, um estoque e uma fila de pedidos para todos os lugares onde o lojista vende (marketplaces, loja própria, WhatsApp e balcão).

---

## 1. Problema

Pequenos e médios lojistas vendem ao mesmo tempo no Mercado Livre, na Shopee, na Magalu e em outros marketplaces, além do WhatsApp, do Instagram e da loja física. Cada canal tem o seu painel, o seu cadastro de anúncio e o seu estoque.

Consequências:

- vende o que não tem (estoque não conversa entre canais) ou deixa produto parado;
- cadastra o mesmo produto várias vezes, um anúncio por marketplace;
- kits (ex.: camiseta + boné) não baixam o estoque das peças;
- pedidos e notas fiscais espalhados em vários painéis;
- nenhuma visão única de quanto vendeu, onde e o quê.

Hoje quem resolve isso são os hubs/ERPs como Bling e Tiny (Olist), que costumam ser complexos para quem tem de 1 a 10 pessoas e ficam caros quando se somam módulos.

## 2. Proposta de valor

*"Cadastre uma vez, venda em todo lugar: anúncios, estoque, kits, pedidos e notas de todos os seus canais num lugar só, com uma loja própria incluída."*

O centro é a **operação de vendas multicanal**. Cada lugar de venda é um **canal** que segue o mesmo contrato: marketplace, loja própria, WhatsApp e pedido manual.

Diferenciais pretendidos: simples para equipes pequenas, preço justo, a raposa (Foxy AI) como assistente do sistema, gerando anúncios e respondendo perguntas sobre os dados, e base sólida para crescer (kits, multicanal e fiscal pensados desde o domínio).

## 3. Público

- **Primeiros clientes:** lojistas de **roupas e acessórios** (já interessados). Precisam de variações (tamanho/cor), grade e estoque por variação.
- **Desenho geral:** o domínio atende e-commerce em geral (cosméticos, eletrônicos, casa, papelaria...). Nada no modelo é específico de moda; atributos de variação são livres.
- 1 a 10 pessoas na operação, já vendendo (em marketplace e/ou WhatsApp), pouca familiaridade técnica.
- **Fora do público inicial:** grandes operações com vários CDs, restaurantes/delivery, serviços com agenda (isso é o Foxy Studio).

## 4. Personas e papéis

| Persona | O que faz | Papel no Core |
|---|---|---|
| **Dono(a)** | Tudo; conecta canais; vê números; assinatura | `Owner` |
| **Gerente** | Catálogo, anúncios, estoque, pedidos, relatórios | `commerce.manager` |
| **Atendente / expedição** | Registra pedido manual, separa e envia, consulta estoque | `commerce.attendant` |
| **Comprador** | Compra na loja própria ou pelo WhatsApp | **Não é User do Core**: é `Customer` do Commerce |

## 5. Escopo

### 5.1 Mapa de módulos (visão completa)

```text
Foxy Commerce
├── Catalog        produtos, variações (atributos livres), kits, categorias, preços, imagens
├── Inventory      saldo por variação, movimentações, reservas; kit consome os componentes
├── Listings       anúncio por canal (título, preço, categoria e atributos do canal) ligado ao produto
├── Channels       contrato único de canal + conectores (Mercado Livre, Shopee, Magalu, Amazon...)
├── Orders         pedidos de todos os canais numa fila só, ciclo de vida único
├── Customers      compradores (da loja, do WhatsApp, manuais; dados de marketplace só o necessário)
├── Storefront     loja própria pública + carrinho + "finalizar pelo WhatsApp"
├── Payments       loja própria: pagamento por fora com baixa manual (ver 5.4)
├── Fulfillment    separação, envio, retirada, rastreio (marketplace: segue a logística do canal)
├── Fiscal         fase A: importar a nota emitida pelo canal; fase B: emitir NF-e e enviar ao canal
└── Insights       vendas por canal, mais vendidos, estoque baixo, ticket médio
```

### 5.2 Regras do domínio que valem desde o primeiro dia

- **Variante é a unidade vendável e estocável** (camiseta P azul). Produto sem variação tem uma variante implícita. Atributos são livres (tamanho, cor, voltagem, sabor...).
- **Kit** é uma variante composta: lista de (variante componente, quantidade). O kit **não tem estoque próprio**: o disponível é calculado pelos componentes (`mín(saldo do componente ÷ quantidade)`), e vender um kit gera movimentação de baixa em cada componente.
- **Estoque único para todos os canais.** Só muda por `StockMovement` (entrada, ajuste, venda, cancelamento, devolução). Toda mudança de saldo dispara a sincronização para os anúncios ligados àquela variante (e aos kits que a usam).
- **Anúncio (Listing) ≠ Produto.** Um produto pode ter um anúncio por canal (ou mais de um), com título, preço, categoria e atributos próprios do canal. O anúncio é criado e editado **dentro do Foxy Commerce** e publicado no canal.
- **Pedido tem snapshot** de nome, SKU, preço e canal; o pedido de marketplace guarda o ID externo e nunca é duplicado (importação idempotente).
- **Canal é um conector atrás de um contrato** (conectar via OAuth, publicar/atualizar anúncio, atualizar estoque/preço, importar pedidos, receber notificações, ler/enviar nota). Adicionar um marketplace novo = escrever um conector, sem mexer no domínio.
- Todo dado pertence a uma `Organization` (a loja).

### 5.3 Fluxo principal

```text
Lojista cadastra produto + variações + kits + estoque (uma vez)
        ↓
Cria anúncios no Foxy Commerce e publica em cada canal conectado
        ↓
Venda acontece em qualquer canal ──► pedido entra na fila única
   (Mercado Livre, Shopee, Magalu, loja própria, WhatsApp, balcão)
        ↓
Estoque baixa uma vez ──► sincroniza o novo saldo em todos os outros canais
        ↓
Separa → envia (logística do canal ou própria) → concluído
        ↓
Nota: importada do canal (fase A) ou emitida pelo sistema (fase B)
        ↓
Painel mostra vendas por canal e estoque
```

### 5.4 Pagamento do comprador (decidido)

- **Marketplaces:** o próprio marketplace cobra o comprador e repassa ao lojista. O Commerce só registra o status.
- **Loja própria e WhatsApp:** **pagamento por fora, com baixa manual.** A loja mostra o Pix e os dados de pagamento **do próprio lojista**; o lojista confirma o recebimento e o pedido segue. A Foxy nunca recebe dinheiro do comprador (mesma regra do Foxy Studio). Gateway automático só no futuro, com conta do próprio lojista (subconta/split), se for decidido.

### 5.5 Fases propostas

| Fase | Entrega | Por quê |
|---|---|---|
| **1 — Base + loja própria** | Login único e equipe (Identity/Membership do Core), assinatura Stripe pelo Foxy Admin, catálogo com variações e kits, estoque único, pedidos manuais (balcão/WhatsApp), loja própria em `ecommerce.foxy.net.br/{loja}` com carrinho, "finalizar pelo WhatsApp" e Pix do lojista, link de produto/catálogo para mandar no WhatsApp, painel, a raposa como assistente | Os clientes já podem usar e pagar; prepara o domínio que o hub precisa |
| **2 — Hub: primeiro marketplace** | Contrato de canal + conector **Mercado Livre**: conectar conta (OAuth), criar e publicar anúncio, sincronizar estoque e preço, importar pedidos, importar a nota emitida pelo canal quando a API permitir | É o maior marketplace do país e tem a API mais aberta; valida o contrato de canal |
| **3 — Mais canais** | Conectores Shopee, Magalu (e outros, como Amazon), na ordem que os clientes pedirem | Cada um depende de aprovação de app parceiro pelo marketplace (passo do responsável) |
| **4 — Fiscal próprio** | Emissão de NF-e pelo sistema via provedor fiscal (com o certificado A1 do lojista) e envio da nota a cada canal; etiquetas e frete | Mais complexo e regulado; entra quando o hub estiver estável |

A raposa, desde a fase 1: assistente do sistema (já existe) + **gerar título e descrição de anúncio** a partir do produto/foto (D7 da v0.1).

### 5.6 Fora do escopo por enquanto

Gateway de pagamento próprio, PDV com maquininha, múltiplos depósitos, lotes/validade, domínio próprio do lojista, cotação automática de frete, ERP financeiro completo.

## 6. Modelo de domínio (conceitual)

```text
Product ──< Variant ──1 StockLevel ──< StockMovement
   │          │
   │          └──< KitComponent (variante componente + quantidade)   [variante do tipo kit]
   ├── Category, Images(file_id)
   └──< Listing ──> Channel(conexão da loja com um canal)  ── external_id, status, preço/atributos do canal
                       │
Customer ──< Order ──< OrderItem ──> Variant (snapshot)
               ├── channel + external_id (idempotente)
               ├── PaymentRecord (manual / do marketplace)
               ├── Fulfillment
               └── FiscalDocument (importado ou emitido)
```

### 6.1 Ciclo de vida do pedido

```text
placed ──► awaiting_payment ──► paid ──► in_fulfillment ──► shipped/ready_for_pickup ──► completed
   │               │
   └──── canceled ◄┘   (devolve o estoque; pedido de marketplace segue o status do canal)
```

- Pedido de marketplace já chega `paid` (ou no status que o canal informar) e o status é espelhado do canal.
- Loja própria/WhatsApp: entra `awaiting_payment` com **reserva** de estoque; o lojista dá baixa manual → `paid`. Reserva expira se não houver pagamento (prazo configurável). Detalhe final na arquitetura.

### 6.2 Eventos de integração candidatos

| Evento | Quem reage |
|---|---|
| `StockChanged` | Channels (empurra o saldo para os anúncios da variante e dos kits que a usam) |
| `OrderImported` / `OrderPlaced` | Inventory (reserva/baixa), Notifications (avisar o lojista) |
| `OrderPaid` | Inventory, Fulfillment, Notifications (comprador) |
| `OrderCanceled` | Inventory (devolve), Channels (informa o canal se for o caso) |
| `ListingPublished` / `ListingFailed` | Notifications, painel de anúncios com erro |
| `StockLow` | Notifications |

## 7. Capacidades do Core que o Commerce usa

| Capacidade | Uso | Nível | Observação |
|---|---|---|---|
| **Identity** | Login de lojistas e equipe | **N1** | Hoje o Core só tem o login do console. Vira o login único da Foxy (fase 3 do Admin). |
| **Organization** | A loja é a organização | **N1** | Já existe (Foxy AI). |
| **Membership / Authorization** | Convite + papéis fixos (`commerce.*`) | **N1** | Novo. |
| **Execution Context** | Isolamento do tenant | **N1** | Base. |
| **Plans / Subscription / Billing** | Assinatura do lojista | **N1** | **Stripe desde o início**, gerido pelo Foxy Admin. |
| **Entitlements** | Limites (nº de produtos, canais conectados, uso da IA) | **N1** | |
| **Events / Jobs** | Sincronização com canais, filas, reprocessamento | **N1** | Fila confiável é crítica no hub. |
| **Files** | Imagens de produto, logo | **N1** | Armazenamento a definir (ver D-C5). |
| **Notifications** | E-mail e WhatsApp | **N1** | Reaproveitar o canal de comunicados do Admin. |
| **Audit** | Ajustes de estoque, preço, cancelamentos, equipe, conexões de canal | **N1** | |
| **Foxy AI** | Assistente + gerar anúncio | **N1** | Já em produção; o Commerce vira mais uma integração. |
| **Backoffice (Foxy Admin)** | Contas, suporte, promoções | **N1** | Já existe; recebe o produto `commerce`. |

## 8. O que o Commerce não faz

- Não implementa login, organizações ou papéis: usa o Core.
- Não recebe dinheiro do comprador (5.4).
- Não lê banco de outro produto (Foxy Studio, Foxy AI); só contratos.
- Não emite nota fiscal até a fase 4.
- Não fala com a Anthropic diretamente: usa a Foxy AI.

## 9. Decisões já tomadas

| # | Decisão |
|---|---|
| D1 | Proposta de valor = hub multicanal com loja própria (seção 2). |
| D2 | Primeiros clientes: lojistas de roupas e acessórios; desenho geral para e-commerce. |
| D3 | Necessidades iniciais: loja própria, estoque e pedidos, vender pelo WhatsApp, integração com marketplaces, kits, anúncios criados no sistema. |
| D4 | Pagamento do comprador: por fora, com baixa manual (marketplaces cobram por conta própria). |
| D5 | Assinatura do lojista via Stripe desde o início. |
| D6 | O Foxy Studio segue separado; sem migração. |
| D7 | IA: assistente do sistema + gerar título/descrição de anúncio. |
| D8 | Comprador sem login no MVP (checkout como convidado). |
| D9 | Stack: a mesma do Core (Laravel 13, PHP 8.4, PostgreSQL), no app foxy2. |
| D10 | Nota fiscal: primeiro importar a emitida pelo canal; emissão própria depois (fase 4). |

## 10. Decisões pendentes

| # | Decisão | Recomendação |
|---|---|---|
| **D-C1** | Ordem das fases 1 e 2: loja própria primeiro ou Mercado Livre primeiro? | Depende de onde os clientes interessados já vendem. Se a maior parte do faturamento vem do Mercado Livre, inverter (hub primeiro, loja depois). |
| **D-C2** | Quais marketplaces, em que ordem | Mercado Livre → Shopee → Magalu → demais. |
| **D-C3** | Endereço da loja própria | `ecommerce.foxy.net.br/{loja}` no início (sem DNS/certificado curinga); subdomínio por loja depois. |
| **D-C4** | Preço e planos do Commerce | Definir com base em nº de produtos/canais; proposta na etapa de planos. |
| **D-C5** | Armazenamento de imagens | S3 (barato e escala) em vez do disco do EC2. |

**Passos que só o responsável faz (deixados para o fim, sem travar o resto):** conta de desenvolvedor/app no Mercado Livre (e depois Shopee/Magalu), produto e preços do Commerce no Stripe, bucket S3, provedor fiscal na fase 4.

## 11. Próxima etapa

Com D-C1 a D-C5 respondidas, este documento vira v1.0 e passamos ao **recorte do Core** (o que entra de Identity/Membership/Billing) e à **arquitetura do Commerce** (módulos, tabelas, contrato de canal, filas, rotas). Código só depois da aprovação da arquitetura.
