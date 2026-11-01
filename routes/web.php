<?php

use App\Demo\Http\ConsoleController;
use App\Demo\Http\LoginController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/console');

// Console de testes da equipe Foxy (docs/02-foxy-ai.md, seção 12).
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
