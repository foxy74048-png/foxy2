# Foxy Commerce — Recorte do Core e arquitetura concreta

> **Status:** v1.0. F1–F10 aprovadas em 2026-11-07. Fatias 1 a 5 e 7 implementadas, mais o painel financeiro (parte da 11) e a loja demo (seção 11).
> **Base:** `03-foxy-commerce.md` v1.0, `01-core-conceitual.md`, `05-arquitetura.md` (stack, padrões e infraestrutura já em produção).
> **Critério:** mesmo da Foxy AI: menor custo no início e tudo preparado para crescer; o domínio já nasce multicanal.

## 1. Resumo

| Item | Escolha |
|---|---|
| Onde roda | No app foxy2 (mesmo deploy de `ai.foxy.net.br` e do Foxy Admin), módulo novo `app/Commerce`. Rotas presas ao endereço `ecommerce.foxy.net.br`. |
| Stack | A de sempre: PHP 8.4, Laravel 13, PostgreSQL (SQLite nos testes), Blade com o visual do Core. |
| Novas dependências | `stripe/stripe-php` (assinatura) e `league/flysystem-aws-s3-v3` (imagens no S3). Nada mais. |
| Lojas | Cada loja é uma `Organization` da integração "Foxy Commerce". Os dados ficam em tabelas `commerce_*` com `BelongsToOrganization`. |
| Conectores | Mercado Livre primeiro, atrás de um contrato `ChannelConnector`. Nos testes, um `FakeConnector` (como o `FakeProvider` da IA). Nenhum teste chama marketplace de verdade. |
| Filas | Driver `database` (já configurado). A sincronização com os canais roda em jobs. |

## 2. Recorte do Core (o que entra agora na Platform)

O Commerce é o primeiro produto com **usuários de cliente**. Por isso entram agora as peças de acesso que a Foxy AI deixou no papel (`04-recorte-platform.md`).

| Capacidade | Hoje | Passa a ter |
|---|---|---|
| **Identity** | `users` = só a equipe Foxy (console e Admin) | Mesma tabela para todos (base do login único), com `is_staff`. Cadastro, verificação de e-mail e recuperação de senha. **Console e Admin passam a exigir `is_staff`** (F1). |
| **Membership** | — | `memberships` (organização, usuário, papel, situação) e `invitations` (e-mail, papel, token com hash, validade). |
| **Authorization** | — | Papéis fixos `owner`, `manager`, `attendant` → lista de permissões `commerce.*` em config. Middleware `commerce.can:permissao`. Sem papéis customizados. |
| **Execution Context** | api_client, ai_agent, system | `forUser(user, membership)`: principal `user`. O middleware monta o contexto pela loja escolhida na sessão (quem tem mais de uma loja escolhe). |
| **Billing / Subscription** | Não existe na foxy2 (os pacotes de IA são cobrados pelo Studio) | `subscriptions` genérica (organização, produto, plano, situação, fim do teste, fim do período, ids do Stripe) + webhook do Stripe. Checkout e portal do Stripe, então **nenhum dado de cartão passa pela Foxy**. |
| **Entitlements** | Uso da IA | Limites por plano do Commerce (nº de produtos, canais conectados, usuários) via `Entitlements`. |
| **Files** | — | `files` (organização, dono lógico, caminho, tipo, tamanho). Disco `s3` em produção, `local` em teste. |
| **Notifications** | `EmailSender`, `WhatsAppSender` (comunicados) | Reaproveitados: e-mail para o comprador e para o lojista; WhatsApp só para o lojista (número da Foxy). |
| **Audit** | `Audit::record()` | Reaproveitado (estoque, preço, cancelamento, equipe, conexão de canal). |
| **Foxy Admin** | Contas chegam por retrato HTTP | O Commerce grava a sua `ProductAccount` direto pelo serviço da Platform (mesmo app). Contas, MRR, suporte, comunicados e promoções passam a valer para o Commerce sem nada novo. |

## 3. Estrutura de pastas

```text
app/
├── Platform/            (existente) + Identity/, Memberships/, Billing/, Files/
├── Ai/                  (existente)
├── Admin/               (existente)
└── Commerce/
    ├── Stores/          loja (1:1 com Organization): slug, logo, cores, WhatsApp, Pix, entrega
    ├── Catalog/         Product, Variant, Category, ProductImage, KitComponent, gerador de grade
    ├── Inventory/       StockLevel, StockMovement, StockService (única porta para mexer no saldo)
    ├── Orders/          Order, OrderItem, OrderEvent, OrderService (ciclo de vida)
    ├── Customers/       Customer
    ├── Channels/        ChannelConnection, Listing, contrato ChannelConnector, caixa de entrada, jobs de sync
    │   └── MercadoLivre/   conector
    ├── Storefront/      loja pública, carrinho, checkout de convidado, Pix estático
    ├── Fiscal/          FiscalDocument (importado; emissão própria só na fase 4)
    ├── Insights/        painel
    ├── Assistant/       manual + tools de leitura para a raposa
    └── Http/            controllers do app do lojista
```

Regras: `Commerce` só usa `Platform` pelas interfaces públicas e a Foxy AI **só pela API** (F8). `Platform` não conhece `Commerce`. Só `Inventory/StockService` altera saldo, só `Orders/OrderService` muda status e só os conectores falam com marketplaces.

## 4. Banco de dados

Todas com `organization_id` indexado, IDs ULID e valores em **centavos inteiros** (F6).

| Tabela | Campos principais | Observação |
|---|---|---|
| `memberships` | organization_id, user_id, role, status | Único por (organização, usuário). |
| `invitations` | organization_id, email, role, token_hash, expires_at, accepted_at | |
| `subscriptions` | organization_id, product, plan, status, trial_ends_at, current_period_end, stripe_customer_id, stripe_subscription_id | Genérica: outros produtos usam a mesma. |
| `files` | organization_id, owner_type, disk, path, mime, size | |
| `commerce_stores` | organization_id, slug (único), name, logo_file_id, colors, whatsapp, pix_key, pix_name, payment_instructions, delivery (json), published_at | |
| `commerce_categories` | organization_id, name, parent_id | |
| `commerce_products` | organization_id, category_id, name, description, brand, status, attributes (json: NCM, peso, medidas...) | |
| `commerce_variants` | organization_id, product_id, sku (único na loja), options (json: `{"Tamanho":"P","Cor":"Azul"}`), price_cents, cost_cents, gtin, is_kit, status | Unidade vendável. |
| `commerce_kit_components` | organization_id, kit_variant_id, component_variant_id, quantity | Kit não tem saldo próprio. |
| `commerce_product_images` | organization_id, product_id, variant_id?, file_id, position | |
| `commerce_stock_levels` | organization_id, variant_id (único), on_hand, reserved | Disponível = on_hand − reserved. |
| `commerce_stock_movements` | organization_id, variant_id, type, quantity (±), order_id?, reason, user_id?, created_at | Só inclusão. |
| `commerce_customers` | organization_id, name, email, phone, document?, address (json) | Só o necessário (LGPD). |
| `commerce_orders` | organization_id, number (sequencial por loja), channel, connection_id?, external_id?, customer_id, status, payment_status, payment_method, subtotal/shipping/discount/total_cents, shipping (json), tracking_code, reserved_until, paid_at | Único em (connection_id, external_id): importação idempotente. |
| `commerce_order_items` | organization_id, order_id, variant_id, sku, name, options, quantity, unit_price_cents | Snapshot. |
| `commerce_order_events` | organization_id, order_id, from, to, by, note, created_at | Histórico do pedido. |
| `commerce_channel_connections` | organization_id, channel, external_account_id, name, access_token/refresh_token (**criptografados**), token_expires_at, status, last_synced_at, settings | |
| `commerce_listings` | organization_id, connection_id, product_id, external_id, title, price_cents, external_category, attributes (json), status, last_error, synced_at | Anúncio criado no sistema. |
| `commerce_listing_variants` | organization_id, listing_id, variant_id, external_variation_id | |
| `commerce_channel_inbox` | connection_id, topic, resource, received_at, processed_at, attempts, last_error | Notificações recebidas: respondidas na hora, processadas em job. |
| `commerce_fiscal_documents` | organization_id, order_id, source (canal/manual/emitida), access_key, number, series, issued_at, xml_file_id | |

## 5. Estoque (o coração do hub)

- **Uma porta só:** `StockService::move(variant, quantity, type, order?)` abre transação, trava a linha do saldo (`lockForUpdate`), grava o movimento e atualiza `on_hand`/`reserved`. Não existe outra forma de mudar saldo.
- **Kit:** vender um kit gera um movimento por componente. O disponível do kit é `mín(disponível do componente ÷ quantidade)`, calculado na hora.
- **Quando mexe** (F7):
  - pedido de marketplace importado → baixa direta (o canal já vendeu);
  - pedido da loja própria/WhatsApp → **reserva** até a baixa manual do pagamento ou até `reserved_until` (padrão 48 h, configurável na loja); expirou → cancela e devolve;
  - pedido manual → o atendente marca se já está pago (baixa) ou não (reserva);
  - cancelamento/devolução → movimento inverso.
- **Depois de cada mudança** (fora da transação): evento `StockChanged` → job único por variante (`ShouldBeUnique`, junta várias mudanças seguidas) que recalcula a variante e os kits que a usam e empurra o novo disponível para todos os anúncios ligados.
- **Venda dupla no último item** (duas vendas quase juntas em canais diferentes): não dá para impedir 100%, porque o marketplace vende sem nos consultar. O hub reduz a janela empurrando o saldo em segundos, e a tela de pedidos mostra **"estoque negativo"** para o lojista resolver. O saldo pode ficar negativo de propósito: esconder isso seria pior.

## 6. Canais

### 6.1 Contrato

```php
interface ChannelConnector {
    authorizationUrl(state) / connect(code): ChannelConnection     // OAuth do vendedor
    refreshToken(connection)
    categories(query) / categoryAttributes(category)               // para montar o anúncio
    publish(listing) / update(listing) / pause(listing) / close(listing)
    pushStock(listing, [variation => available]) / pushPrice(listing)
    parseNotification(request): InboxItem                          // o que chegou
    fetchOrder(connection, externalId): ExternalOrder              // sempre busca na API (não confia no corpo)
    recentOrders(connection, since): ExternalOrder[]               // conferência periódica
    fetchInvoice(connection, externalId): ?ExternalInvoice         // nota emitida pelo canal, se a API der
}
```

A loja própria e o pedido manual também são canais (`storefront`, `manual`), só que internos: não têm conector externo.

### 6.2 Fluxo

- **Conectar:** o dono clica em "Conectar Mercado Livre" → OAuth → tokens criptografados. A renovação do token é automática (job antes de vencer).
- **Anunciar:** editor de anúncio no sistema (título, categoria do canal, atributos obrigatórios da categoria, preço do canal, quais variantes) → job `PublishListing` → status `publicando` → `ativo` ou `erro` com a mensagem do canal na tela.
- **Receber:** `POST /webhooks/commerce/{canal}` público, com throttle; grava na caixa de entrada e responde 200 na hora. O job busca o recurso na API do canal e só então cria ou atualiza o pedido (idempotente pelo `external_id`). A autenticidade vem dessa busca.
- **Rede de segurança:** a cada 10 min, `recentOrders()` por conexão pega o que alguma notificação perdeu.
- **Limites dos canais:** jobs de canal com `RateLimited` por conexão e novas tentativas com espera crescente. Falha repetida → conexão marcada "precisa de atenção" e aviso ao lojista.
- Detalhes da API do Mercado Livre (escopos, tópicos de notificação, variações, nota) são conferidos na documentação oficial na fatia do conector, com testes sobre respostas gravadas.

## 7. Loja própria

- Endereço `ecommerce.foxy.net.br/{slug}` (F9). Slugs reservados: `app`, `entrar`, `cadastro`, `webhooks`, `stripe`, `convite`, `senha`...
- Rota pública: **sem usuário logado**. A loja é achada pelo slug e o contexto é montado com `ExecutionContext::forSystem(org, 'storefront')`. Todo POST tem throttle.
- Carrinho na sessão → checkout de convidado (nome, WhatsApp, e-mail, entrega/retirada) → pedido `awaiting_payment` com reserva.
- Pagamento por fora (`03` seção 5.4): a página do pedido mostra a chave Pix **do lojista** e um **QR Pix estático com o valor** (gerado no próprio sistema pelo padrão do Banco Central, sem gateway; o dinheiro vai direto para a conta do lojista). Também tem o botão "Enviar pedido pelo WhatsApp" (`wa.me` do lojista com o resumo).
- Página do pedido para o comprador por **URL assinada**. E-mails de status para o comprador.
- Visual: tema próprio da loja (logo e cores do lojista), separado do visual do Core.

## 8. App do lojista

- `ecommerce.foxy.net.br/app`, com `<x-core::layout>` (roxo sóbrio, opções importantes sempre visíveis).
- **Cadastro:** nome, e-mail, senha, nome da loja → cria User + Organization + Store + Membership `owner` + Subscription em teste + ProductAccount (aparece no Foxy Admin na hora). Verificação de e-mail.
- **Menu:** Painel, Pedidos, Produtos, Estoque, Anúncios, Canais, Clientes, Loja própria, Equipe, Assinatura.
- **Grade:** gerador de variantes (ex.: Tamanho P/M/G × Cor Azul/Preto → 6 variantes com SKU sugerido), ponto crítico para roupas.
- **Assinatura:** teste vencido sem pagamento → app em modo leitura com a tela de assinatura em destaque; a loja própria mostra "loja indisponível" e a sincronização pausa. Os dados são mantidos.

## 9. Raposa no Commerce

- O Commerce vira mais um sistema anfitrião da Foxy AI: publica manual e catálogo de tools de leitura (`vendas_periodo`, `pedidos_pendentes`, `estoque_baixo`, `mais_vendidos`, `anuncios_com_erro`) e executa as tools com as permissões de quem pergunta.
- Chamada pela API da Foxy AI usando o pacote `foxy-ai-client` com o transporte `InternalApi` (já existe em `app/Demo`; sobe para a Platform). Assim passa pelos mesmos middlewares, limites e créditos de qualquer sistema, sem rede (F8).
- Capacidade `escrita`: "gerar título e descrição" no editor de produto e de anúncio.

## 10. Segurança e LGPD

- Isolamento por organização em todo model `commerce_*`, com teste de vazamento entre lojas (como na IA).
- Tokens dos marketplaces com cast `encrypted` (APP_KEY), nunca em log.
- Staff separado de cliente (F1); Admin e console com teste de "cliente não entra".
- Comprador: só o necessário para entregar e emitir nota. Dado de comprador vindo do marketplace: só o que o pedido precisa.
- Webhooks públicos não confiam no corpo: buscam o recurso na API do canal. O do Stripe confere a assinatura.
- Auditoria: ajuste de estoque, mudança de preço, cancelamento, equipe, conexão e desconexão de canal.

## 11. Ordem de implementação (fatias, cada uma com testes e commit)

1. ✔ **Identity e equipe:** `is_staff` (os usuários atuais viram staff), cadastro, login e recuperação no endereço do Commerce, memberships, convites, contexto `forUser`, permissões, loja (Store), ProductAccount no Admin.
   Feito em 2026-11-07: `users.is_staff` (quem já tinha login virou staff; `foxy:console-user:create` cria staff; console e Admin com middleware `staff` e login do console só para staff), `memberships`, `invitations` (token só no e-mail, hash no banco, 7 dias), `ExecutionContext::forUser`, middlewares `commerce.store` (loja da sessão ou a única) e `commerce.can:permissão`, papéis em `config/commerce.php`, `commerce_stores` (slug sem repetição nem palavra reservada), `OpenStore` (organização + loja + dono + conta no Admin via `AccountSync`). Rotas em `routes/commerce.php`, presas a `ecommerce.foxy.net.br`: `/entrar`, `/cadastro`, `/senha`, `/verificar-email`, `/convite/{token}`, `/app`, `/app/equipe`, `/app/lojas`, `/app/nova-loja`. **Cadastro público fechado** até o lançamento (`FOXY_COMMERCE_SIGNUP_OPEN=false`); com ele fechado, só a equipe Foxy abre loja (pelo `/app/nova-loja`) e funcionários entram por convite. Pendente para a fatia 2: as ações da ficha da conta no Admin (estender teste, suspender...) ainda não são lidas pelo Commerce.
2. ✔ **Assinatura:** `subscriptions`, Stripe Checkout + portal + webhook (testes com Stripe falso), planos e limites, modo leitura, MRR do Commerce no Admin.
   Feito em 2026-11-07: `stripe/stripe-php`; `subscriptions` (por organização e produto) e `stripe_events` (cada evento uma vez); `BillingGateway` → `StripeGateway` (e `FakeBillingGateway` nos testes); webhook `POST https://ecommerce.foxy.net.br/stripe/webhook` (confere a assinatura; ignora o que é do Foxy Studio, que usa a mesma conta do Stripe); `/app/assinatura` só para o dono (Checkout, portal, "obrigado"); quem assina no teste só é cobrado quando o teste acaba (`trial_end`). Acesso: fundador, assinante ou teste em dia = uso completo; senão **só leitura** (vê tudo, não altera; pode assinar); loja suspensa = bloqueada. Planos e limites em `config/commerce.php` (`CommerceBillingPlans::limit`; o de pessoas já vale nos convites). **Preços provisórios** (Inicial R$ 79, Profissional R$ 149, Completo R$ 249) até D-C4. Ações do Foxy Admin para lojas do Commerce (`commerce:comandos`, a cada minuto): estender teste (nunca para quem paga), suspender, reativar, Parceiro Fundador; a raposa e chamados ainda não. `commerce:contas` (de hora em hora) mantém a situação no Admin.
3. ✔ **Catálogo:** categorias, produtos, gerador de grade, variantes, kits, imagens (Files).
   Feito em 2026-11-07: `files` (Core: `AppPlatformFilesFile`, pasta `org/{organização}/...`, disco em `COMMERCE_IMAGES_DISK`: `public` até o S3 existir, o que exige `php artisan storage:link` no servidor; `league/flysystem-aws-s3-v3` já instalado), `commerce_categories` (um nível de subcategoria), `commerce_products` (`type` simple/kit, `option_names` da grade, `specs` com peso, medidas e NCM), `commerce_variants` (SKU único na loja, `options`, preço e custo em centavos, GTIN, ativa), `commerce_kit_components`, `commerce_product_images` (até 10 por produto, JPG/PNG/WEBP até 5 MB). `CatalogService` é a única porta: grade de até 3 características e 200 variantes, SKU sugerido (`NOME-P-AZUL`, sem repetir), acrescentar valores cria só as combinações que faltam, mudança de preço vai para a auditoria, kit sem kit dentro. Telas: `/app/produtos` (busca por nome/SKU, categoria, situação), novo produto/kit, edição (variantes em lote, grade, peças do kit, fotos, dados, arquivar), `/app/categorias`. Atendente só vê; limite de produtos ativos pelo plano. A loja do contexto é resolvida antes do model da rota (`prependToPriorityList`), então id de outra loja dá 404.
4. ✔ **Estoque:** StockService, entradas e ajustes, disponível de kit, alerta de estoque baixo.
   Feito em 2026-11-07: `commerce_stock_levels` (físico, reservado, mínimo; um por variante) e `commerce_stock_movements` (só inclusão: tipo, quantidade, reserva, físico depois, pedido, motivo, quem). `StockService` (único, por requisição) trava a linha, grava o movimento e, depois da transação, dispara **um** `StockChanged` com as variantes e os kits afetados (os canais vão escutar na fatia 7). Operações: entrada, saída, contagem (vira ajuste com a diferença), venda (com ou sem reserva), cancelamento/devolução, reserva e liberação. Kit não tem saldo: o movimento vai para cada peça, e o disponível é `mín(disponível da peça ÷ quantidade)`. Saldo negativo permitido e destacado. Telas: `/app/estoque` (entrada em lote por SKU, filtros baixo/zerado/negativo), `/app/estoque/{variante}` (mexer, mínimo, histórico com quem fez); disponível na edição do produto; contador no painel. Atendente só consulta.
5. ✔ **Pedidos e clientes:** pedido manual (balcão/WhatsApp), ciclo de vida, baixa manual de pagamento, envio e rastreio, histórico, painel inicial.
   Feito em 2026-11-08: `commerce_customers`, `commerce_orders` (número por loja, canal, id externo único por canal, status, forma de pagamento, subtotal/desconto/frete/**taxas do canal**/total/**custo**, datas de cada etapa, reserva até) e `commerce_order_items` (snapshot de SKU, nome, preço e custo; kit custa a soma das peças). `OrderService` é a única porta: pago = baixa no estoque; não pago = reserva de 48 h (`commerce:reservas` a cada 15 min cancela e solta); confirmar pagamento baixa da reserva; andamento pago → separando → enviado (rastreio) → concluído; cancelar devolve ou solta. Telas: `/app/pedidos` (filtros de período, situação, canal e busca; planilha CSV para o Excel), novo pedido manual (balcão, WhatsApp, Instagram; cliente existente ou novo), detalhe com próximo passo sempre à vista; `/app/clientes` (total comprado, última compra) e ficha. Cancelar só dono e gerente.
   **Painel financeiro** (`/app`, dono e gerente; `CommerceInsightsFinanceReport`): filtros de período (hoje, 7/30/90 dias, mês, mês passado, ano, 12 meses, datas), canal, forma de pagamento e categoria; indicadores com comparação ao período anterior (faturamento, pedidos, ticket médio, lucro bruto e margem, taxas dos canais, custo das mercadorias, a receber, cancelamentos); faturamento por dia, semana ou mês; por canal (com margem), forma de pagamento, categoria, mais vendidos e melhores clientes. Gráficos em HTML/CSS próprios (`resources/views/commerce/charts`), uma série na cor da marca, dica ao passar o mouse e tabela com os números. Lucro bruto = total − frete − taxas − custo.
   **Loja demo:** `php artisan commerce:demo [--email=demo@foxy.net.br] [--senha=] [--dias=180] [--fresh]` cria "Moda Demo Foxy" (Parceiro Fundador) com 10 produtos (grade e kits), estoque, 80 clientes e ~860 pedidos em 6 meses em todos os canais, cada um no dia dele. `--fresh` apaga e recria só a demo.
6. **Loja própria:** vitrine pública, carrinho, checkout de convidado, Pix estático, WhatsApp, expiração de reservas, e-mails.
7. ✔ **Canais (base):** contrato, `FakeConnector`, conexões, editor de anúncio, caixa de entrada, jobs de publicação e de estoque, conferência periódica.
   Feito em 2026-11-08: `commerce_channel_connections` (tokens com cast `encrypted`; uma conta de vendedor fica numa loja só), `commerce_listings` + `commerce_listing_variants` (último estoque enviado), `commerce_channel_inbox`. Contrato `CommerceChannelsChannelConnector` + `ExternalOrder` + `ChannelError`; registro em `config/commerce.php` → `channels` (conector `null` = "em breve"; `Channels::fake()` nos testes, com `FakeConnector`). `ChannelSync`: publicar (envia SKU e disponível de cada variante), atualizar, pausar, enviar estoque só do que mudou, importar pedido (idempotente por canal + id; só entra pago; cancelado no canal cancela aqui; SKU desconhecido deixa a conexão "precisa de atenção" com a explicação), processar aviso, conferência. Jobs `PublishListing`, `PushListingStock` (único por anúncio) e `ProcessInboxItem`; `StockChanged` → envio para os anúncios no ar. Rotas: `/app/canais` (conectar com `state` na sessão, retorno em `/app/canais/{canal}/retorno`, desconectar pausa os anúncios), `/app/anuncios` (novo a partir do produto, título com o limite do canal, preço próprio, variantes, publicar/pausar, erro do canal na tela), aviso público `POST /webhooks/commerce/{canal}` (sem CSRF; só guarda e enfileira). Agendador: `commerce:canais` a cada 10 min e **o worker da fila** (`queue:work --stop-when-empty`) a cada minuto, então não há passo extra no servidor (F5).
8. **Mercado Livre:** conector real (testado sobre respostas gravadas), OAuth, categorias e atributos, publicar, estoque e preço, pedidos.
9. **Nota (fase A):** importar a nota emitida pelo canal quando a API der + anexar XML manualmente (qualquer canal).
10. **Raposa no Commerce:** manual, tools de leitura, gerar título e descrição.
11. **Painel completo (Insights):** vendas por canal, mais vendidos, ticket médio, estoque parado.

Depois da fatia 11: conectores Shopee, Magalu e Shein (fase 3), nessa ordem, e então a loja própria (fatia 6); emissão de NF-e própria (fase 4).
**Ordem decidida (D-C1, 2026-11-07):** os clientes vendem hoje no Mercado Livre, Shopee, Magalu e Shein, então os marketplaces vêm antes da loja própria: 1 ✔ → 2 → 3 → 4 → 5 → 7 → 8 → 9 → 10 → 11 → Shopee → Magalu → Shein → 6.

**Passos do responsável (no fim, sem travar as fatias):** produto e preços do Commerce no Stripe + webhook + chaves no `.env` da foxy2; bucket S3 + usuário IAM; app de desenvolvedor no Mercado Livre (client id/secret + URL de retorno); planos e preços do Commerce (D-C4).

**Exceção sugerida:** os acessos de desenvolvedor da Shopee, da Magalu e da Shein dependem de aprovação do marketplace, que pode levar semanas. Vale pedir cedo para não travar a fase 3. O do Mercado Livre costuma sair na hora. Os requisitos de cada um serão confirmados na documentação oficial antes do pedido.

## 12. Decisões para aprovação

| # | Decisão | Recomendação |
|---|---|---|
| **F1** ✔ | Usuários | Cada pessoa tem o próprio login; uma empresa (loja) tem vários logins, um por funcionário, convidados pelo dono com papel (gerente/atendente). Uma tabela `users` para todos, com `is_staff`; console e Admin exigem staff. Aprovado em 2026-11-07. |
| **F2** | Loja no Core | Loja = Organization da integração "Foxy Commerce"; dados em `commerce_*` com `BelongsToOrganization`. |
| **F3** | Assinatura | `stripe/stripe-php` direto (sem Cashier), Checkout + portal; teste grátis de 14 dias sem cartão. |
| **F4** | Imagens | S3 com leitura pública só da pasta de imagens de produto (os marketplaces e a loja precisam da URL); local nos testes. |
| **F5** | Fila | Driver `database`; worker pelo agendador a cada minuto (`queue:work --stop-when-empty`), igual ao cron que já existe. Serviço systemd dedicado quando o volume pedir. |
| **F6** | Dinheiro | Centavos inteiros em todas as tabelas `commerce_*`. |
| **F7** | Estoque | Reserva na loja própria (48 h padrão), baixa direta no marketplace, saldo negativo permitido e sinalizado. |
| **F8** | Raposa | Commerce como anfitrião pela API (`foxy-ai-client` + `InternalApi`), sem atalho para dentro da Foxy AI. |
| **F9** | Endereços | App em `ecommerce.foxy.net.br/app`, loja em `ecommerce.foxy.net.br/{slug}`; subdomínio por loja depois. |
| **F10** ✔ | Ordem | Marketplaces antes da loja própria (D-C1): 1–5, 7–11, Shopee, Magalu, Shein e então a 6. |
