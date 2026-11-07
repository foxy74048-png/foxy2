<?php

use App\Commerce\Http\AuthController;
use App\Commerce\Http\DashboardController;
use App\Commerce\Http\InvitationController;
use App\Commerce\Http\StoreController;
use App\Commerce\Http\TeamController;
use App\Commerce\Http\VerificationController;
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

    Route::middleware('commerce.store')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/equipe', [TeamController::class, 'index'])->middleware('commerce.can:commerce.team.view')->name('team');
        Route::middleware('commerce.can:commerce.team.manage')->group(function () {
            Route::post('/equipe/convites', [TeamController::class, 'invite'])->middleware('throttle:20,1')->name('team.invite');
            Route::post('/equipe/convites/{invitation}/cancelar', [TeamController::class, 'cancelInvitation'])->name('team.invitations.cancel');
            Route::post('/equipe/{membership}/papel', [TeamController::class, 'changeRole'])->name('team.role');
            Route::post('/equipe/{membership}/remover', [TeamController::class, 'remove'])->name('team.remove');
        });
    });
});
