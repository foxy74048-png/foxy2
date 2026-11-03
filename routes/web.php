<?php

use App\Admin\Http\AdminController;
use App\Demo\Http\ConsoleController;
use App\Demo\Http\LoginController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// "/" depende do endereço: foxy.net.br = vitrine da marca; admin.foxy.net.br = Foxy Admin; ai.foxy.net.br = console.
Route::get('/', function (Request $request) {
    $host = strtolower($request->getHost());

    return match (true) {
        in_array($host, config('foxy.hosts.vitrine'), true) => response()->view('vitrine.index'),
        $host === config('foxy.hosts.admin') => redirect()->route('admin.dashboard'),
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
    Route::get('/promocoes', [AdminController::class, 'promotions'])->name('promotions');
    Route::post('/promocoes', [AdminController::class, 'storePromotion'])->name('promotions.store');
    Route::post('/promocoes/{promotion}/situacao', [AdminController::class, 'promotionStatus'])->name('promotions.status');
});
