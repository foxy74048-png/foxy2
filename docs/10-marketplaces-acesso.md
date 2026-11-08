# Foxy Commerce — acesso às APIs dos marketplaces (passos do responsável)

> **Pesquisado em 2026-11-07** nas páginas públicas de desenvolvedores e em guias de terceiros. Algumas páginas oficiais
> bloqueiam leitura automática; o que não foi confirmado na fonte oficial está marcado como **a confirmar**.
> Ordem do Commerce (docs/09, seção 11): Mercado Livre → Shopee → Magalu → Shein.

## O que vale para todos

- **CNPJ da Foxy**, com os dados da empresa (razão social, endereço, telefone). A conta de desenvolvedor é da Foxy, não de uma pessoa. Hoje a Foxy é **MEI** (o responsável pretende passar a ME); a inscrição estadual depende da atividade registrada no MEI. Ponto para o contador: desenvolvimento de software/SaaS (CNAE 62xx) não aparece entre as atividades permitidas ao MEI segundo várias fontes.
- **Endereços (já existem com HTTPS):**
  - retorno da autorização: `https://ecommerce.foxy.net.br/app/canais/{canal}/retorno`
  - notificações: `https://ecommerce.foxy.net.br/webhooks/commerce/{canal}`
  - `{canal}` = `mercadolivre`, `shopee`, `magalu`, `shein`.
- **Política de privacidade e termos do Foxy Commerce** publicados (alguns portais pedem o link).
- **Um lojista piloto por marketplace**, que aceite conectar a conta dele para os testes.
- As chaves (client id/secret, partner key...) **vão direto para o `.env` da foxy2 no servidor**, nunca por chat ou e-mail.

## Mercado Livre — fazer já

1. Entrar com a conta do Mercado Livre **da empresa (CNPJ da Foxy)**. No Brasil, cada conta com os dados validados pode ter **uma só aplicação**, então não usar conta pessoal.
2. Em developers.mercadolivre.com.br → "Minhas aplicações" → criar a aplicação "Foxy Commerce": nome, descrição, logo, redirect URI (HTTPS, acima), permissões de **leitura e escrita** (anúncios, pedidos, envios, perguntas, nota) e URL de notificações (acima), com os tópicos de pedidos, anúncios e envios.
3. Guardar no `.env` da foxy2: `MERCADOLIVRE_CLIENT_ID=` e `MERCADOLIVRE_CLIENT_SECRET=` (o Mercado Livre só aparece como disponível no Foxy com eles).
4. Nas notificações do app, marcar o tópico **orders_v2** (pedidos) com a URL `https://ecommerce.foxy.net.br/webhooks/commerce/mercadolivre`.
5. Testar com o lojista piloto: Marketplaces → Conectar Mercado Livre → criar um anúncio de teste. Moda costuma exigir tabela de medidas (`SIZE_GRID_ID`), que o lojista cria no próprio Mercado Livre.

Sem aprovação prévia: funciona na hora para qualquer vendedor que autorizar. A certificação (Developer Partner Program: Certificado, Silver, Gold, Platinum) é **opcional** e depende de volume de vendas e de usuários ativos. Fica para quando houver clientes.

## Shopee — cadastro já, pedido de "Go Live" depois do Mercado Livre funcionando

1. Em open.shopee.com, criar a conta de desenvolvedor com região **BR** e tipo **Registered Business Seller**. Conta de pessoa física é recusada.
2. Dados exigidos: razão social, CNPJ, **comprovante de inscrição e situação estadual**, endereço da sede, telefone, e aceite da Política de Proteção de Dados.
3. Criar o app do tipo **ERP System** (sistema que atende vários vendedores), com a URL de teste em HTTPS (TLS 1.2).
4. Pedir o Go Live **quando o conector do Mercado Livre estiver no ar**. O guia de ISV da Shopee pede software já funcionando com outros marketplaces, além de contas de teste para a checagem do sistema (guia antigo; **a confirmar** se ainda vale no Brasil).

Prazo: a Shopee fala em cerca de 48 h para o Go Live depois da configuração, mas há relatos de mais de 2 meses de espera. Por isso a conta deve ser criada cedo.
**A confirmar:** a Foxy é de serviços; se não tiver inscrição estadual, ver se a Shopee aceita certidão de isenção/"não contribuinte".

## Magalu — criar o app já e confirmar o credenciamento de integrador

1. Criar a conta no **ID Magalu** (id.magalu.com) com os dados da Foxy.
2. Instalar o **ID Magalu CLI** e criar o client com os escopos de produtos, estoque, preço, pedidos, nota fiscal e webhooks, mais a URL de retorno (acima). Guardar `client_id` e `client_secret` no `.env`.
3. O lojista autoriza pelo Portal do Seller → Integrações → "Listar Integradores".
4. **A confirmar com a Magalu (suporte do portal de desenvolvedores):** a documentação não fala em homologação, mas a tela "Listar Integradores" sugere que o integrador precisa estar credenciado para aparecer ali. Perguntar como a Foxy entra nessa lista e se há ambiente de testes.

Para o lojista vender na Magalu, guias de terceiros citam CNPJ ativo há mais de 3 meses e emissão de NF-e.

## Shein — perguntar antes de cadastrar

1. Em open.sheincorp.com, o caminho é o tipo **Third-Party Partner Platform (ISV)**: criar a aplicação, passar pela revisão, testar com lojas de teste, pedir as permissões de API e ter a URL de notificações ativada pela Shein.
2. **Antes, mandar e-mail para openapi@shein.com** perguntando:
   - se o Open Platform atende vendedores do marketplace **do Brasil** (seller-br.shein.com);
   - quais documentos um ISV brasileiro precisa;
   - quanto tempo leva a revisão.
3. Para vender na Shein, o lojista precisa de CNPJ. Guias de terceiros falam em faturamento mínimo e uso de ERP/hub (**a confirmar**).

## Resumo

| Marketplace | Fazer agora | Esperar |
|---|---|---|
| Mercado Livre | Conta PJ + criar app + chaves no `.env` | Nada |
| Shopee | Conta de desenvolvedor PJ (BR) + app ERP | Go Live depois do Mercado Livre no ar |
| Magalu | ID Magalu + client pelo CLI + perguntar sobre credenciamento | Resposta da Magalu |
| Shein | E-mail para openapi@shein.com | Resposta antes de cadastrar |

## Fontes

- Mercado Livre: [Crie uma aplicação](https://developers.mercadolivre.com.br/pt_br/crie-uma-aplicacao-no-mercado-livre), [Gestão de aplicações](https://developers.mercadolivre.com.br/registre-o-seu-aplicativo), [Developer Partner Program](https://developers.mercadolivre.com.br/pt_br/developer-partner-program), [Permissões funcionais](https://developers.mercadolivre.com.br/permissoes-funcionais)
- Shopee: [Guia prático da Open Platform (BR)](https://seller.br.shopee.cn/edu/article/27314), [API Access Request Process](https://seller.shopee.com.br/edu/article/3445/API--Access-Request-Process), [Open API para ISV](https://seller.shopee.ph/edu/article/7616), [relato de demora](https://www.reclameaqui.com.br/shopee/demora-na-liberacao-da-api-open-platform-shopee-impactando-integracao-e-clientes_qJ66ZhjkiMXcJACg/)
- Magalu: [Como integrar com as APIs](https://developers.magalu.com/docs/first-steps/create-an-application/), [Criar cliente](https://developers.magalu.com/docs/first-steps/create-an-application/create-application/index.html), [Autorização do seller](https://developers.magalu.com/docs/first-steps/create-an-application/authentication-authorization/index.html), [Oruc: requisitos do lojista](https://ajuda.oruc.com.br/integracao-com-a-magalu/)
- Shein: [SHEIN Developer Platform](https://open.sheincorp.com/), [Documentação](https://open.sheincorp.com/documents/system/dad0d1c7-be76-4b03-a735-4e23f012bdd9), [Portal de vendedores BR](https://seller-br.shein.com/)
