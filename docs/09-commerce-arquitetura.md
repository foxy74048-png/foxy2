# Foxy Commerce — Recorte do Core e arquitetura concreta

> **Status:** v1.0. F1–F10 aprovadas em 2026-11-07. Fatias 1 a 11 implementadas, mais a loja demo (seção 11). Faltam Shopee, Magalu e Shein (dependem do acesso de desenvolvedor) e a emissão de NF-e (fase 4).
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
- Chamada pela API da Foxy AI usando o pacote `foxy-ai-client` com o transporte `AppPlatformApiClientsInternalApi` (movido de `app/Demo` em 2026-11-08; agora devolve o contexto de quem chamou ao terminar). Assim passa pelos mesmos middlewares, limites e créditos de qualquer sistema, sem rede (F8).
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
   Feito em 2026-11-07: `commerce_stock_levels` (físico, reservado, mínimo; um por variante) e `commerce_stock_movements` (só inclusão: tipo, quantidade, reserva, físico depois, pedido, motivo, quem). `StockService` (único, por requisição) trava a linha, grava o movimento e, depois da transação, dispara **um** `StockChanged` com as variantes e os kits afetados (os canais vão escutar na fatia 7). Operações: entrada, saída, contagem (vira ajuste com a diferença), venda (com ou sem reserva), cancelamento/devolução, reserva e liberação. Kit não tem saldo: o movimento vai para cada peça, e o disponível é `mín(disponível da peça ÷ quantidade)`. Saldo negativo permitido e destacado. Telas: `/app/estoque` em duas abas, posição (filtros baixo/zerado/negativo) e entrada de mercadoria (`?aba=entrada`: linhas que crescem com Enter, nome e saldo aparecem ao digitar o SKU, últimas entradas), `/app/estoque/{variante}` (mexer, mínimo, histórico com quem fez); disponível na edição do produto; contador no painel. Atendente só consulta.
5. ✔ **Pedidos e clientes:** pedido manual (balcão/WhatsApp), ciclo de vida, baixa manual de pagamento, envio e rastreio, histórico, painel inicial.
   Feito em 2026-11-08: `commerce_customers`, `commerce_orders` (número por loja, canal, id externo único por canal, status, forma de pagamento, subtotal/desconto/frete/**taxas do canal**/total/**custo**, datas de cada etapa, reserva até) e `commerce_order_items` (snapshot de SKU, nome, preço e custo; kit custa a soma das peças). `OrderService` é a única porta: pago = baixa no estoque; não pago = reserva de 48 h (`commerce:reservas` a cada 15 min cancela e solta); confirmar pagamento baixa da reserva; andamento pago → separando → enviado (rastreio) → concluído; cancelar devolve ou solta. Telas: `/app/pedidos` (filtros de período, situação, canal e busca; planilha CSV para o Excel), novo pedido manual (balcão, WhatsApp, Instagram; cliente existente ou novo), detalhe com próximo passo sempre à vista; `/app/clientes` (total comprado, última compra) e ficha. Cancelar só dono e gerente.
   **Painel financeiro** (`/app`, dono e gerente; `CommerceInsightsFinanceReport`): filtros de período (hoje, 7/30/90 dias, mês, mês passado, ano, 12 meses, datas), canal, forma de pagamento e categoria; indicadores com comparação ao período anterior (faturamento, pedidos, ticket médio, lucro bruto e margem, taxas dos canais, custo das mercadorias, a receber, cancelamentos); faturamento por dia, semana ou mês; por canal (com margem), forma de pagamento, categoria, mais vendidos e melhores clientes. Gráficos em HTML/CSS próprios (`resources/views/commerce/charts`), uma série na cor da marca, dica ao passar o mouse e tabela com os números. Lucro bruto = total − frete − taxas − custo.
   **Loja demo:** `php artisan commerce:demo [--email=demo@foxy.net.br] [--senha=] [--dias=180] [--fresh]` cria "Moda Demo Foxy" (Parceiro Fundador) com 10 produtos (grade e kits), estoque, 80 clientes e ~860 pedidos em 6 meses em todos os canais, cada um no dia dele. `--fresh` apaga e recria só a demo.
6. ✔ **Loja própria:** vitrine pública, carrinho, checkout de convidado, Pix estático, WhatsApp, expiração de reservas, e-mails.
   Feito em 2026-11-08: configuração em `/app/loja` (dono e gerente: nome, WhatsApp, cor, logo, sobre, Pix do lojista com chave, nome e cidade do recebedor, instruções de pagamento, entrega por retirada, entrega local com taxa e envio com frete fixo, publicar/tirar do ar; publicar exige Pix e uma entrega). Páginas públicas em `ecommerce.foxy.net.br/{slug}` (`ResolvePublicStore`: só loja publicada; suspensa ou sem assinatura = "indisponível"; contexto da loja do slug): vitrine com busca e categorias, produto com escolha da variante e esgotados, carrinho na sessão (nunca acima do disponível), finalizar (nome, WhatsApp, e-mail, entrega, endereço) → pedido `loja` aguardando pagamento com reserva de 48 h. Página do pedido por **URL assinada** com Pix copia e cola e QR estático com o valor (`CommerceStorefrontPixCode`, BR Code com CRC16; QR por `chillerlan/php-qrcode` em SVG) e "Enviar pedido pelo WhatsApp". E-mail para o dono (pedido novo) e para o comprador. Visual próprio da loja (`<x-storefront::layout>`, cor e logo do lojista). Demo: loja publicada e uma imagem simples por produto.
7. ✔ **Canais (base):** contrato, `FakeConnector`, conexões, editor de anúncio, caixa de entrada, jobs de publicação e de estoque, conferência periódica.
   Feito em 2026-11-08: `commerce_channel_connections` (tokens com cast `encrypted`; uma conta de vendedor fica numa loja só), `commerce_listings` + `commerce_listing_variants` (último estoque enviado), `commerce_channel_inbox`. Contrato `CommerceChannelsChannelConnector` + `ExternalOrder` + `ChannelError`; registro em `config/commerce.php` → `channels` (conector `null` = "em breve"; `Channels::fake()` nos testes, com `FakeConnector`). `ChannelSync`: publicar (envia SKU e disponível de cada variante), atualizar, pausar, enviar estoque só do que mudou, importar pedido (idempotente por canal + id; só entra pago; cancelado no canal cancela aqui; SKU desconhecido deixa a conexão "precisa de atenção" com a explicação), processar aviso, conferência. Jobs `PublishListing`, `PushListingStock` (único por anúncio) e `ProcessInboxItem`; `StockChanged` → envio para os anúncios no ar. Rotas: `/app/canais` (conectar com `state` na sessão, retorno em `/app/canais/{canal}/retorno`, desconectar pausa os anúncios), `/app/anuncios` (novo a partir do produto, título com o limite do canal, preço próprio, variantes, publicar/pausar, erro do canal na tela), aviso público `POST /webhooks/commerce/{canal}` (sem CSRF; só guarda e enfileira). Agendador: `commerce:canais` a cada 10 min e **o worker da fila** (`queue:work --stop-when-empty`) a cada minuto, então não há passo extra no servidor (F5).
8. ✔ **Mercado Livre:** conector real (testado sobre respostas gravadas), OAuth, categorias e atributos, publicar, estoque e preço, pedidos.
   Feito em 2026-11-08: `CommerceChannelsMercadoLivreMercadoLivreConnector` (API `api.mercadolibre.com`, site MLB), ligado só quando `MERCADOLIVRE_CLIENT_ID` está no `.env`. OAuth (`auth.mercadolivre.com.br`), `/users/me`; token de 6 h renovado sozinho (o refresh token é de uso único: guarda o novo); 401 renova e tenta de novo; 429/5xx tentam de novo. Publicar: categoria sugerida pelo título (`domain_discovery`) se não informada, `BRAND`/`MODEL` do produto, atributos digitados no anúncio (`CÓDIGO=valor`, ex.: `GENDER`, `SIZE_GRID_ID`, `LISTING_TYPE=gold_pro`), fotos pela URL pública, variações por Tamanho/Cor/Voltagem/Sabor com `SELLER_SKU`, descrição. Estoque e preço **por variação** (`PUT /items/{id}/variations/{v}`; nunca a lista inteira, que apagaria as outras). Pedidos pelo tópico `orders_v2` e por `/orders/search`, com taxa (`sale_fee` × quantidade) e comprador; SKU pelo `seller_sku` ou pela variação ligada ao anúncio. Erros do Mercado Livre aparecem na tela como vieram. **A confirmar com o app criado:** categorias no modelo "User Products" (preço por variação) e a tabela de medidas obrigatória em moda.
9. ✔ **Nota (fase A):** importar a nota emitida pelo canal quando a API der + anexar XML manualmente (qualquer canal).
   Feito em 2026-11-08: `commerce_fiscal_documents` (pedido, origem, chave de 44 dígitos única na loja, número, série, emissão, valor, XML). No pedido: anexar o XML (lido sem rede e sem entidades externas) ou digitar a chave (dígito verificador conferido; número e série tirados da chave); XML em disco **privado** (`local`), baixado só pela rota autenticada; aviso se o valor da nota difere do pedido. Pedidos: filtro "vendidos sem nota"/"com nota", número e chave na planilha; painel: "Vendas sem nota (30 dias)". Canais: interface opcional `ProvidesInvoices`; a conferência busca as notas que faltam (pedidos vendidos nos últimos 30 dias). **Mercado Livre ainda não implementa** `ProvidesInvoices`: o endereço e o formato da nota na API precisam ser confirmados com o app criado. A demo traz notas em ~85% dos pedidos concluídos.
10. ✔ **Raposa no Commerce:** manual, tools de leitura, gerar título e descrição.
   Feito em 2026-11-08: `CommerceAssistantCommerceHost` (perfil `foxy-commerce`, manual do Commerce e 6 consultas de leitura: vendas_resumo, vendas_mais_vendidos, pedidos_pendentes, pedido_buscar, estoque_baixo, anuncios_com_erro, cada uma ligada a uma permissão do papel) e `CommerceFox` (chave do sistema criptografada em disco, conversa por sessão, plano de perguntas da loja em `COMMERCE_AI_PLAN`, padrão `ia-250`). `php artisan commerce:raposa` publica perfil, manual e consultas (rodar no deploy quando o manual mudar). Janela da raposa em todas as telas do app (`/app/raposa/mensagem`, `/nova`, `/escolher`), com escolha da raposa no primeiro uso; a IA age em nome da pessoa (`on_behalf_of`). Capacidade `escrita` (título e descrição de anúncio) para quem cuida do catálogo. Funciona também com a loja só para consulta.
11. ✔ **Painel completo (Insights):** vendas por canal, mais vendidos, ticket médio, estoque parado.
   Feito em 2026-11-08 (além do painel financeiro da fatia 5): `/app/relatorios` (dono e gerente; `CommerceInsightsReports`) com três abas. **Mês a mês**: 6/12/24 meses por canal, gráfico, faturamento, variação (o mês atual contra os mesmos dias do mês anterior), pedidos, ticket, lucro e margem. **Estoque parado e giro**: por variante, vendido em 30/60/90/180 dias (peças vendidas dentro de kits contam), quantos dias o estoque dura, última venda, dinheiro parado pelo custo e situação (parado, lento > 120 dias, acabando < 15 dias, sem estoque, saudável), com planilha. **Curva ABC**: A até 80% do faturamento acumulado, B até 95%, C o resto, com margem por produto. Demo: reposição semanal só do que está acabando e um produto encalhado de propósito.

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

## 13. Depois das fatias (2026-11-08)

- **Suporte na central do Foxy Admin:** `/app/suporte` (qualquer pessoa da loja) grava o chamado direto em `product_tickets` (sem retrato por HTTP), ligado à conta da loja; a equipe responde em `/admin/suporte` e o comando `support.reply` (via `commerce:comandos`) grava a resposta no chamado e manda e-mail ao dono. Funciona com a loja só para consulta.
- **Revisão de segurança dos endereços públicos:** vitrine (só loja publicada, contexto da loja do slug, textos escapados, cor validada, logo sem SVG, POST com limite), página do pedido com URL assinada, avisos dos canais (o pedido é sempre buscado na API do canal), Stripe com assinatura, retorno OAuth com `state` na sessão, XML da nota sem rede. Corrigido: **injeção de fórmula nas planilhas** (`Commerce\Insights\Csv`: texto começando com = + - @ sai com apóstrofo; números continuam números), porque o nome do comprador vem da loja pública.
- **Tudo editável ou suspendível (2026-11-09):** listas com coluna de ações (produtos: Editar/Desativar/Reativar; anúncios: Editar, Pausar/Reativar e Apagar quando ainda é rascunho ou deu erro; clientes: Editar). Pedido ganhou "Editar dados do pedido" (`OrderService::updateDetails`: observações, rastreio, forma de pagamento fora do marketplace; desconto e frete só nos pedidos digitados, recalculando o total; itens não se editam: cancelar e lançar de novo). Equipe: Suspender/Reativar (`Membership::SUSPENDED` ocupa a vaga, não entra; ninguém suspende a si mesmo nem o último dono). Movimento de estoque continua só inclusão: corrige-se com contagem/ajuste. Paginação do Core em português (`core.pagination`, `Paginator::defaultView`).
- **Exportar tudo (2026-11-09):** botões "Planilha" e "PDF" no topo de toda lista e relatório (painel financeiro, relatórios mês a mês/estoque/ABC, pedidos, produtos, categorias, estoque e histórico da variante, clientes e pedidos do cliente, anúncios, equipe). Mesmo endereço da tela com `?exportar=planilha|pdf`: usa os filtros da tela e ignora a paginação. `Commerce\Insights\Export` (seções com colunas tipadas; planilha = CSV ; com BOM e proteção contra fórmula; PDF = dompdf, A4, paisagem se tiver mais de 6 colunas, até 1.500 linhas). Os endereços antigos `/app/pedidos/exportar` e `/app/relatorios/estoque.csv` continuam.
- **Conjunto e troca de peça (2026-11-09):** "Novo kit" tem dois modos: kit fixo ou **conjunto com tamanho de cada peça** (`CatalogService::createSet/syncSet`, coluna `commerce_products.kit_set`): escolhe 2–3 produtos com grade e, opcionalmente, a característica que precisa ser igual (Cor); cada combinação vira uma variante-kit com as suas peças (máx. 300), então estoque, loja e canais funcionam como kit. "Criar combinações que faltam" depois de um tamanho novo; não apaga (desativa-se a combinação). **Trocar peça** no pedido (`OrderService::exchangeItem`): devolve a peça (movimento "Troca") e baixa a nova, ou só troca a reserva se não pago; troca parcial divide o item; preço novo só em pedido digitado; anota na observação.
- **Foto por variação (2026-11-09):** `commerce_product_images.option_name/option_value` — a foto vale para o produto todo (vazio) ou para um valor da grade (ex.: Cor = Nude). Ao enviar, "Estas fotos são de"; em cada foto, trocar a variação, "Capa" e apagar (até 20 fotos). Loja própria: a foto principal troca quando a cliente escolhe a cor. Mercado Livre: cada variação leva as fotos dela + as gerais (máx. 12 no anúncio). Lista de produtos marca "sem foto" e filtra "só os sem foto"; o produto sem foto mostra aviso.
- **Financeiro e WhatsApp da loja (2026-11-10):** `/app/financeiro` (dono e gerente; `commerce.finance.manage`) com abas A receber, A pagar e Fluxo de caixa (semanas, com as vencidas na primeira linha), filtros e Planilha/PDF. `commerce_receivables` (parcelas do **crediário/fiado** — forma de pagamento nova no pedido: a peça sai do estoque e o total vira N parcelas mensais no nome da cliente — e contas avulsas; baixa total ou parcial, o que falta vira conta nova; reabrir, editar, cancelar; pedido cancelado cancela as parcelas abertas). `commerce_payables` (única, parcelada ou todo mês; cancelar esta e as próximas). Tudo por `FinanceService`. **WhatsApp da loja** (`/app/whatsapp`): número da loja conectado por QR numa instância própria (`foxy_commerce_{org}`) da mesma Evolution do Studio (`Platform\Messaging\WhatsAppConnections`, com as lições do Studio); "Cobrar" envia pelo número da loja ou, sem conexão, abre o wa.me com o texto pronto; textos editáveis com `{cliente} {loja} {descricao} {valor} {vencimento} {pix}` (chave + Pix copia e cola com o valor). `commerce:lembretes` (de hora em hora, 9h–19h, pausa 6–10 s): antes do vencimento (N dias), no dia e nos dias de atraso escolhidos, uma vez por etapa (anotado em `reminders`). Histórico em `commerce_whatsapp_messages`. Clientes: cadastro sem pedido e, na ficha, contas e mensagens.
- **Pedido mais automático (2026-11-10):** cliente por busca nas cadastradas (mostra WhatsApp, última compra e quanto deve em aberto; o que não achar vira cliente nova junto com o pedido; `?cliente=` abre já escolhida, botão "Novo pedido para ela" na ficha). Itens por **produto → tamanho/cor** (só o que está ativo, com o estoque de cada um; aceita o SKU digitado). "+ Produto que não está cadastrado" (`POST /app/produtos/rapido`, quem pode catálogo e estoque): nome, categoria existente ou nova (sem duplicar), preço, custo, tamanhos, cores e estoque inicial de cada variação — já entra no pedido. Prévia das parcelas do crediário.
- **Remessas de fornecedor / consignação (2026-11-10):** Estoque › "Remessas de fornecedor (importar)" (`/app/remessas`, quem pode catálogo). Comprovante em PDF (`smalot/pdfparser`) ou texto colado → `Supply\ReceiptParser` (colunas grudadas ou separadas; ref., descrição "NOME COR | TAM", código de barras, marca, preço, qtd/dev./ent.; cabeçalho: empresa, nº, data, fechamento, total) → rascunho agrupado por produto (`commerce_supply_batches.groups`). Conferência: nome, origem por produto (**consignado** ou **comprado**), categoria, preço de venda e custo (preço do comprovante como venda − comissão % ou custo + margem %, arredondado em ,90), cor/tamanho/qtd por linha. Lançar (`SupplyService::confirm`): acha o produto pelo código de barras (gtin) ou nome, cria com grade Cor/Tamanho (combinações que não vieram ficam inativas) ou acrescenta as novas, grava gtin/custo, dá entrada no estoque e lança conta a pagar das compradas no fechamento. **Acerto** (`settle`): "devolver" sugere o que há hoje (até o que veio); devolvido sai do estoque, vendido × custo vira conta a pagar. Produto ganhou `supplier` e `supply_mode` (editáveis; etiqueta na lista).
- **Avisos, privacidade, raposa e revisão (2026-11-10):** Painel (dono e gerente) com contas vencidas, a receber e a pagar em 7 dias e acertos de consignação até 7 dias. Loja própria: `/{slug}/privacidade` (aviso LGPD: dados, finalidade, base legal, retenção, direitos pelo WhatsApp da loja), link no rodapé e no checkout. Raposa: manual atualizado (pedido novo, crediário, troca, conjunto, fotos por cor, financeiro, WhatsApp, remessas, loja) e consultas `contas_a_receber`, `contas_a_pagar` (finance.manage), `remessas_em_aberto` (catalog.manage), `cliente_buscar` (orders.manage) — **rodar `commerce:raposa` após o deploy**. Revisão de segurança dos módulos novos: escopo por loja nas rotas e consultas, permissões, saída escapada, CSV protegido, link wa.me só com dígitos; corrigido: importação de remessa não respeitava o limite de produtos do plano.
- **Adicionar sempre à vista (2026-11-11):** toda tela de lista tem o botão roxo "+ …" no topo (`commerce._add`: leva à tela de cadastro ou abre, rola e foca o formulário da própria página — `data-add-target`; links com `#id` também abrem). Financeiro e Clientes abrem o formulário sozinhos quando ainda não há nada lançado. Painel com a faixa "Lançar:" (pedido, produto, cliente, conta a receber/pagar, entrada de estoque, remessa), conforme a permissão.
- **Entrada de mercadoria que aprende + nota fiscal XML (2026-11-11):** `commerce_suppliers` (nome, nome no documento, CNPJ, origem, base de preço e % da última remessa) e `commerce_supplier_codes` (memória: `bar:`/`cod:`/`desc:` → variação; a descrição só quando a linha não tem código). `Supply\SupplierMemory`: `supplier()` reconhece pelo CNPJ (o leitor do comprovante pega o "CNPJ:" do cabeçalho) ou nome; `recall()` põe as linhas reconhecidas (código de barras da variação ou memória) no produto, cor e tamanho conferidos, e junta as irmãs novas ao mesmo produto; `remember()` grava no lançamento. A remessa guarda `supplier_id`, `source` (comprovante/nfe) e `installments`. "Como na última remessa" (base/percentual vazios) usa o da fornecedora; 1ª vez: comprovante = venda 30%, nota = custo 100%. `Supply\NfeParser`: XML de NF-e de qualquer emissor (namespace removido; DOCTYPE/ENTITY recusados; LIBXML_NONET): emitente (fantasia ou razão, CNPJ), nº, emissão, itens (cProd, xProd, cEAN/cEANTrib válidos, qCom, (vProd − vDesc)/qCom), CFOP x917 = consignado, senão compra; duplicatas → contas a pagar proporcionais nas datas. `ReceiptParser::splitSize` tira o tamanho de descrições sem "|" ("TAM M", última palavra PP–EGG/G1–G4/U/36–60/44B, com espaço, - ou /); sem referência, agrupa pelas primeiras palavras sem a última (a cor). Próximos passos combinados: leitor genérico de PDF com modelo por fornecedora, bipar pelo celular, IA para foto/escaneado.
- **Consignação: maletas e recebidas (2026-11-11):** menu "Consignação (maletas)" (`/app/consignacao`, `orders.manage`). `commerce_consignments` (nº por loja, pessoa = `Customer`, cliente ou revendedora com comissão %, saída, acerto até, situação, pedido gerado) e `commerce_consignment_items` (variação, nome, qtd., preço no envio, voltou). `Consignment\ConsignmentService`: `open`/`add` reservam (`StockService::reserve` com motivo; só o disponível; a mesma peça soma) — a reserva não expira (não é de pedido); `settle` libera tudo e vende o que ficou via `OrderService::place` (canal `consignacao`, pago; comissão = desconto; "fica a receber" = crediário com parcelas). Tela de nova maleta e reposição com busca/bipe (código de barras, SKU ou nome; datalist com o disponível), romaneio em Planilha/PDF, lista no WhatsApp da pessoa, acerto com totais ao vivo. Painel: maletas com acerto atrasado ou em até 2 dias; atalho "Maleta / consignado". Aba "Recebidas" (`/app/consignacao-recebida`, estoque + catálogo): por remessa aberta, vendido (veio − disponível), já deve (vendido × custo), venda pelo preço atual, lucro, % vendido, dias para o acerto e peças sem venda; totais por fornecedora.
- **Link da maleta / portal da revendedora (2026-11-11):** `commerce_customers.portal_token` (32 caracteres, único, criado ao abrir a maleta na tela; "Trocar o link" → `POST /app/consignacao/{id}/novo-link` gera outro). Rota pública `GET /{slug}/r/{token}` (`ResellerPortalController`, throttle 60/min) com `commerce.public:portal` — o middleware aceita loja não publicada nesse modo (continua bloqueando suspensa/assinatura vencida). Só leitura: maletas abertas (itens, preço, comissão por peça e total se vender tudo), acertos anteriores (vendido, comissão = desconto do pedido, pago), contas a receber em aberto e Pix copia e cola da próxima (se a loja tem Pix). Layout `bare` (sem busca/carrinho, `noindex`, `no-referrer`). O link vai na lista do WhatsApp da maleta.
- **Loja em modo catálogo (2026-11-11):** `commerce_stores.mode` (`loja` = carrinho + Pix, `catalogo` = pedido pelo WhatsApp) e `hide_sold_out`. `Store::publishProblem()`: catálogo publica só com WhatsApp; loja exige Pix + entrega; salvar num modo que não fecha tira do ar e explica. No catálogo, carrinho/finalizar redirecionam para a vitrine e o produto mostra "Pedir pelo WhatsApp" (GET `/{slug}/p/{produto}/whatsapp`, throttle; valida a variação do produto e o disponível; redireciona para wa.me com produto, opção, quantidade, preço e link; nada reservado). "Esconder esgotados": vitrine filtra pelo disponível (kits pelas peças) e pagina em memória. Meta Open Graph (foto, nome, preço) na vitrine e no produto para a prévia do link. "Compartilhar no WhatsApp"/"Copiar link" em Loja própria e no produto.
- **Importar planilhas e fotos (2026-11-11):** menu "Importar planilhas e fotos" (`/app/importar`, `ImportController`; permissão por tipo: produtos = catálogo + estoque, receber/pagar = financeiro, fotos = catálogo). `ImportsSheet` lê .xlsx (zip + XML, sem biblioteca, 1ª aba, limite 5.000 linhas e 60 MB de XML) e .csv (; , tab; UTF-8 ou Windows-1252), acha a linha de títulos e normaliza (sem acento, minúsculas); aceita apelidos de coluna. Fluxo: enviar → prévia em cache 2 h (chave por loja + pessoa + token) → lançar. `ProductImport`: uma linha por variação, mesmo nome = um produto; grade Cor/Tamanho pelo `CatalogGridMatcher` (extraído do `SupplyService`, que agora o usa): acha por código de barras, SKU ou nome, cria a grade só com o que veio (o resto inativo) ou acrescenta; preço/custo/SKU/GTIN por variação; estoque "somar" (entrada) ou "igualar" (contagem); categoria criada pelo nome; respeita o limite de produtos do plano. `FinanceImport`: a receber (cliente pelo WhatsApp — 8 últimos dígitos — ou nome, senão cadastra; uma linha por parcela) e a pagar (categoria pelo nome); linha "pago" entra baixada. Modelos CSV com exemplos de lingerie. Fotos em lote (`/app/importar/fotos`, uma por requisição pelo JS): `PhotoMatcher` pelo nome do arquivo — SKU, código de barras ou nome do produto (+ cor → foto marcada para a cor).
