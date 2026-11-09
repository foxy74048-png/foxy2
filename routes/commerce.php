<?php

use App\Commerce\Http\AuthController;
use App\Commerce\Http\BillingController;
use App\Commerce\Http\CategoryController;
use App\Commerce\Http\ChannelController;
use App\Commerce\Http\CustomerController;
use App\Commerce\Http\DashboardController;
use App\Commerce\Http\FiscalController;
use App\Commerce\Http\FoxController;
use App\Commerce\Http\InvitationController;
use App\Commerce\Http\ListingController;
use App\Commerce\Http\OrderController;
use App\Commerce\Http\ProductController;
use App\Commerce\Http\ReportController;
use App\Commerce\Http\StockController;
use App\Commerce\Http\StoreController;
use App\Commerce\Http\StorefrontController;
use App\Commerce\Http\StoreSettingsController;
use App\Commerce\Http\SupportController;
use App\Commerce\Http\TeamController;
use App\Commerce\Http\VerificationController;
use App\Platform\Billing\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Foxy Commerce em ecommerce.foxy.net.br (docs/09, seção 8). Carregado por routes/web.php, preso ao endereço
| e com nomes "commerce.*".
*/

Route::get('/', function () {
    if (auth()->check() || config('commerce.signup_open')) {
        return redirect()->route('commerce.dashboard');
    }

    // Antes do lançamento, quem chega pelo endereço vê os produtos na vitrine da marca.
    return redirect()->away(rtrim(config('foxy.products.vitrine_url'), '/').'/#produtos');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/entrar', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/entrar', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::get('/cadastro', [AuthController::class, 'showSignup'])->name('signup');
    Route::post('/cadastro', [AuthController::class, 'signup'])->middleware('throttle:5,1');
    Route::get('/senha', [AuthController::class, 'showForgot'])->name('password.request');
    Route::post('/senha', [AuthController::class, 'sendReset'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/senha/{token}', [AuthController::class, 'showReset'])->name('password.reset');
    Route::post('/senha-nova', [AuthController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
});

// Webhook do Stripe (público, sem CSRF; confere a assinatura do Stripe).
Route::post('/stripe/webhook', StripeWebhookController::class)->middleware('throttle:120,1')->name('stripe.webhook');

// Avisos dos marketplaces (públicos, sem CSRF): só guardam e enfileiram; o pedido é buscado na API do canal.
Route::post('/webhooks/commerce/{channel}', [ChannelController::class, 'webhook'])->middleware('throttle:600,1')->name('channels.webhook');

// Convite de equipe: público (o token é a prova); funciona logado ou não.
Route::get('/convite/{token}', [InvitationController::class, 'show'])->name('invitation.show');
Route::post('/convite/{token}', [InvitationController::class, 'accept'])->middleware('throttle:10,1')->name('invitation.accept');

Route::middleware('auth')->group(function () {
    Route::post('/sair', [AuthController::class, 'logout'])->name('logout');
    Route::get('/verificar-email', [VerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/verificar-email/{id}/{hash}', [VerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/verificar-email', [VerificationController::class, 'send'])->middleware('throttle:6,1')->name('verification.send');
});

Route::middleware(['auth', 'verified:commerce.verification.notice'])->prefix('app')->group(function () {
    Route::get('/lojas', [StoreController::class, 'choose'])->name('stores.choose');
    Route::post('/lojas', [StoreController::class, 'switch'])->name('stores.switch');
    Route::get('/nova-loja', [StoreController::class, 'create'])->name('stores.new');
    Route::post('/nova-loja', [StoreController::class, 'store'])->middleware('throttle:5,1')->name('stores.create');

    Route::middleware(['commerce.store', 'commerce.subscription'])->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // Loja própria: configuração (fatia 6)
        Route::middleware('commerce.can:commerce.store.manage')->group(function () {
            Route::get('/loja', [StoreSettingsController::class, 'edit'])->name('store.settings');
            Route::post('/loja', [StoreSettingsController::class, 'update'])->name('store.settings.update');
            Route::post('/loja/publicar', [StoreSettingsController::class, 'publish'])->name('store.publish');
        });

        // Relatórios (fatia 11)
        Route::middleware('commerce.can:commerce.reports.view')->group(function () {
            Route::get('/relatorios', [ReportController::class, 'index'])->name('reports');
            Route::get('/relatorios/estoque.csv', [ReportController::class, 'stockCsv'])->name('reports.stock-csv');
        });

        // Suporte: chamados vão para a central do Foxy Admin
        Route::get('/suporte', [SupportController::class, 'index'])->name('support');
        Route::post('/suporte', [SupportController::class, 'store'])->middleware('throttle:10,1')->name('support.store');

        // Janela da raposa (fatia 10)
        Route::middleware('throttle:20,1')->group(function () {
            Route::post('/raposa/mensagem', [FoxController::class, 'message'])->name('fox.message');
            Route::post('/raposa/nova', [FoxController::class, 'reset'])->name('fox.reset');
            Route::post('/raposa/escolher', [FoxController::class, 'setup'])->name('fox.setup');
        });

        Route::middleware('commerce.can:commerce.billing.manage')->group(function () {
            Route::get('/assinatura', [BillingController::class, 'show'])->name('billing');
            Route::post('/assinatura/assinar', [BillingController::class, 'checkout'])->middleware('throttle:10,1')->name('billing.checkout');
            Route::post('/assinatura/gerenciar', [BillingController::class, 'portal'])->middleware('throttle:10,1')->name('billing.portal');
            Route::get('/assinatura/obrigado', [BillingController::class, 'thanks'])->name('billing.thanks');
        });

        // Catálogo (fatia 3)
        Route::middleware('commerce.can:commerce.catalog.view')->group(function () {
            Route::get('/produtos', [ProductController::class, 'index'])->name('products');
            Route::get('/categorias', [CategoryController::class, 'index'])->name('categories');
        });
        Route::middleware('commerce.can:commerce.catalog.manage')->group(function () {
            Route::get('/produtos/novo', [ProductController::class, 'create'])->name('products.create');
            Route::post('/produtos', [ProductController::class, 'store'])->name('products.store');
            Route::get('/produtos/{product}', [ProductController::class, 'edit'])->name('products.edit');
            Route::post('/produtos/{product}', [ProductController::class, 'update'])->name('products.update');
            Route::post('/produtos/{product}/variantes', [ProductController::class, 'updateVariants'])->name('products.variants');
            Route::post('/produtos/{product}/grade', [ProductController::class, 'extendGrid'])->name('products.grid');
            Route::post('/produtos/{product}/kit', [ProductController::class, 'updateComponents'])->name('products.kit');
            Route::post('/produtos/{product}/arquivar', [ProductController::class, 'archive'])->name('products.archive');
            Route::post('/produtos/{product}/fotos', [ProductController::class, 'uploadImages'])->middleware('throttle:30,1')->name('products.images');
            Route::post('/produtos/{product}/fotos/{image}/apagar', [ProductController::class, 'deleteImage'])->name('products.images.delete');
            Route::post('/categorias', [CategoryController::class, 'store'])->name('categories.store');
            Route::post('/categorias/{category}', [CategoryController::class, 'update'])->name('categories.update');
            Route::post('/categorias/{category}/apagar', [CategoryController::class, 'destroy'])->name('categories.destroy');
        });

        // Pedidos e clientes (fatia 5)
        Route::middleware('commerce.can:commerce.orders.manage')->group(function () {
            Route::get('/pedidos', [OrderController::class, 'index'])->name('orders');
            Route::get('/pedidos/exportar', [OrderController::class, 'export'])->name('orders.export');
            Route::get('/pedidos/novo', [OrderController::class, 'create'])->name('orders.create');
            Route::post('/pedidos', [OrderController::class, 'store'])->middleware('throttle:60,1')->name('orders.store');
            Route::get('/pedidos/{order}', [OrderController::class, 'show'])->name('orders.show');
            Route::post('/pedidos/{order}/editar', [OrderController::class, 'update'])->name('orders.update');
            Route::post('/pedidos/{order}/pago', [OrderController::class, 'pay'])->name('orders.pay');
            Route::post('/pedidos/{order}/andamento', [OrderController::class, 'advance'])->name('orders.advance');
            Route::post('/pedidos/{order}/nota', [FiscalController::class, 'store'])->middleware('throttle:30,1')->name('orders.fiscal');
            Route::get('/pedidos/{order}/nota/{document}/xml', [FiscalController::class, 'download'])->name('orders.fiscal.xml');
            Route::post('/pedidos/{order}/nota/{document}/apagar', [FiscalController::class, 'destroy'])->name('orders.fiscal.destroy');
            Route::get('/clientes', [CustomerController::class, 'index'])->name('customers');
            Route::get('/clientes/{customer}', [CustomerController::class, 'show'])->name('customers.show');
            Route::post('/clientes/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        });
        Route::post('/pedidos/{order}/cancelar', [OrderController::class, 'cancel'])->middleware('commerce.can:commerce.orders.cancel')->name('orders.cancel');

        // Marketplaces e anúncios (fatia 7)
        Route::middleware('commerce.can:commerce.channels.manage')->group(function () {
            Route::get('/canais', [ChannelController::class, 'index'])->name('channels');
            Route::post('/canais/{channel}/conectar', [ChannelController::class, 'connect'])->middleware('throttle:10,1')->name('channels.connect');
            Route::get('/canais/{channel}/retorno', [ChannelController::class, 'callback'])->middleware('throttle:10,1')->name('channels.callback');
            Route::post('/canais/conexoes/{connection}/desconectar', [ChannelController::class, 'disconnect'])->name('channels.disconnect');
            Route::get('/anuncios', [ListingController::class, 'index'])->name('listings');
            Route::get('/anuncios/novo', [ListingController::class, 'create'])->name('listings.create');
            Route::post('/anuncios', [ListingController::class, 'store'])->name('listings.store');
            Route::get('/anuncios/{listing}', [ListingController::class, 'edit'])->name('listings.edit');
            Route::post('/anuncios/{listing}', [ListingController::class, 'update'])->name('listings.update');
            Route::post('/anuncios/{listing}/publicar', [ListingController::class, 'publish'])->middleware('throttle:30,1')->name('listings.publish');
            Route::post('/anuncios/{listing}/apagar', [ListingController::class, 'destroy'])->name('listings.destroy');
            Route::post('/anuncios/{listing}/pausar', [ListingController::class, 'pause'])->name('listings.pause');
        });

        // Estoque (fatia 4)
        Route::middleware('commerce.can:commerce.stock.view')->group(function () {
            Route::get('/estoque', [StockController::class, 'index'])->name('stock');
            Route::get('/estoque/{variant}', [StockController::class, 'show'])->name('stock.show');
        });
        Route::middleware('commerce.can:commerce.stock.adjust')->group(function () {
            Route::post('/estoque/entrada', [StockController::class, 'receive'])->name('stock.receive');
            Route::post('/estoque/{variant}/ajuste', [StockController::class, 'adjust'])->name('stock.adjust');
            Route::post('/estoque/{variant}/minimo', [StockController::class, 'minimum'])->name('stock.minimum');
        });

        Route::get('/equipe', [TeamController::class, 'index'])->middleware('commerce.can:commerce.team.view')->name('team');
        Route::middleware('commerce.can:commerce.team.manage')->group(function () {
            Route::post('/equipe/convites', [TeamController::class, 'invite'])->middleware('throttle:20,1')->name('team.invite');
            Route::post('/equipe/convites/{invitation}/cancelar', [TeamController::class, 'cancelInvitation'])->name('team.invitations.cancel');
            Route::post('/equipe/{membership}/papel', [TeamController::class, 'changeRole'])->name('team.role');
            Route::post('/equipe/{membership}/suspender', [TeamController::class, 'suspend'])->name('team.suspend');
            Route::post('/equipe/{membership}/remover', [TeamController::class, 'remove'])->name('team.remove');
        });
    });
});

// Loja própria pública (fatia 6): ecommerce.foxy.net.br/{slug}. Por último, para não engolir os outros endereços.
Route::prefix('{slug}')->where(['slug' => '[a-z0-9-]+'])->middleware('commerce.public')->name('storefront.')->group(function () {
    Route::get('/', [StorefrontController::class, 'home'])->name('home');
    Route::get('/p/{product}', [StorefrontController::class, 'product'])->name('product');
    Route::get('/carrinho', [StorefrontController::class, 'cart'])->name('cart');
    Route::post('/carrinho', [StorefrontController::class, 'add'])->middleware('throttle:60,1')->name('cart.add');
    Route::post('/carrinho/atualizar', [StorefrontController::class, 'update'])->middleware('throttle:60,1')->name('cart.update');
    Route::get('/finalizar', [StorefrontController::class, 'checkout'])->name('checkout');
    Route::post('/finalizar', [StorefrontController::class, 'place'])->middleware('throttle:10,1')->name('place');
    Route::get('/pedido/{order}', [StorefrontController::class, 'order'])->middleware('signed')->name('order');
});
