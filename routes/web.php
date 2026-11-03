<?php

use App\Admin\Http\AccountAdminController;
use App\Admin\Http\AdminController;
use App\Admin\Http\SupportAdminController;
use App\Admin\Http\AnnouncementController;
use App\Admin\Http\InsightController;
use App\Platform\Http\AnnouncementUnsubscribeController;
use App\Demo\Http\ConsoleController;
use App\Demo\Http\LoginController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// "/" depende do endereço: foxy.net.br = vitrine da marca; admin.foxy.net.br = Foxy Admin;
// ecommerce.foxy.net.br = vitrine (Commerce em breve); ai.foxy.net.br = console.
Route::get('/', function (Request $request) {
    $host = strtolower($request->getHost());

    return match (true) {
        in_array($host, config('foxy.hosts.vitrine'), true) => response()->view('vitrine.index'),
        $host === config('foxy.hosts.admin') => redirect()->route('admin.dashboard'),
        $host === config('foxy.hosts.commerce') => redirect()->away(rtrim(config('foxy.products.vitrine_url'), '/').'/#produtos'),
        default => redirect('/console'),
    };
})->name('home');

// Console de testes da equipe Foxy (docs/02-foxy-ai.md, seção 12). O login vale também para o Foxy Admin.
Route::get('/console/login', [LoginController::class, 'show'])->name('login');
Route::post('/console/login', [LoginController::class, 'login'])->middleware('throttle:5,1');
Route::post('/console/logout', [LoginController::class, 'logout'])->name('logout');

Route::middleware('auth')->prefix('console')->group(function () {
    Route::get('/', [ConsoleController::class, 'index'])->name('console');
    // Endpoints da janela da raposa (o console faz o papel do servidor do sistema).
    Route::post('/assistant/messages', [ConsoleController::class, 'message']);
    Route::post('/assistant/reset', [ConsoleController::class, 'reset']);
    Route::post('/assistant/fox', [ConsoleController::class, 'chooseFox']);
    Route::post('/compare', [ConsoleController::class, 'compare']);
});

// Foxy Admin (docs/08): administrativo da empresa, por cima de todos os produtos.
Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/contas', [AdminController::class, 'accounts'])->name('accounts');
    Route::get('/contas/{account}', [AccountAdminController::class, 'show'])->name('accounts.show');
    Route::post('/contas/{account}/acao', [AccountAdminController::class, 'command'])->middleware('throttle:30,1')->name('accounts.command');
    Route::post('/contas/{account}/presente', [AccountAdminController::class, 'gift'])->middleware('throttle:30,1')->name('accounts.gift');
    Route::post('/contas/{account}/ajuda', [AccountAdminController::class, 'help'])->middleware('throttle:20,1')->name('accounts.help');
    Route::get('/suporte', [SupportAdminController::class, 'index'])->name('support');
    Route::post('/suporte/{ticket}', [SupportAdminController::class, 'reply'])->middleware('throttle:30,1')->name('support.reply');
    Route::get('/promocoes', [AdminController::class, 'promotions'])->name('promotions');
    Route::post('/promocoes', [AdminController::class, 'storePromotion'])->name('promotions.store');
    Route::post('/promocoes/{promotion}/situacao', [AdminController::class, 'promotionStatus'])->name('promotions.status');
    Route::get('/comunicados', [AnnouncementController::class, 'index'])->name('announcements');
    Route::post('/comunicados', [AnnouncementController::class, 'store'])->name('announcements.store');
    Route::get('/comunicados/{announcement}', [AnnouncementController::class, 'show'])->name('announcements.show');
    Route::post('/comunicados/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
    Route::post('/comunicados/{announcement}/teste', [AnnouncementController::class, 'test'])->middleware('throttle:10,1')->name('announcements.test');
    Route::post('/comunicados/{announcement}/enviar', [AnnouncementController::class, 'send'])->name('announcements.send');
    Route::post('/comunicados/{announcement}/cancelar', [AnnouncementController::class, 'cancel'])->name('announcements.cancel');
    Route::get('/ideias', [InsightController::class, 'index'])->name('insights');
    Route::post('/ideias/{insight}', [InsightController::class, 'update'])->name('insights.update');
});

// Link de cada comunicado para sair da lista (público, assinado; confirma por POST).
Route::middleware(['signed', 'throttle:30,1'])->group(function () {
    Route::get('/comunicados/sair/{delivery}', [AnnouncementUnsubscribeController::class, 'show'])->name('announcements.unsubscribe');
    Route::post('/comunicados/sair/{delivery}', [AnnouncementUnsubscribeController::class, 'confirm']);
});
