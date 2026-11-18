<?php

use App\Finance\Http\AuthController;
use App\Finance\Http\DashboardController;
use App\Finance\Http\InvitationController;
use App\Finance\Http\PeopleController;
use App\Finance\Http\ProfileController;
use App\Finance\Http\VerificationController;
use App\Finance\Http\WalletController;
use Illuminate\Support\Facades\Route;

/*
| Foxy Finanças em financas.foxy.net.br (docs/12). Carregado por routes/web.php, preso ao endereço e com
| nomes "finance.*".
*/

Route::get('/', function () {
    if (auth()->check() || config('finance.signup_open')) {
        return redirect()->route('finance.dashboard');
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

// Convite para uma carteira: público (o token é a prova); funciona logado ou não.
Route::get('/convite/{token}', [InvitationController::class, 'show'])->name('invitation.show');
Route::post('/convite/{token}', [InvitationController::class, 'accept'])->middleware('throttle:10,1')->name('invitation.accept');

Route::middleware('auth')->group(function () {
    Route::post('/sair', [AuthController::class, 'logout'])->name('logout');
    Route::get('/verificar-email', [VerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/verificar-email/{id}/{hash}', [VerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/verificar-email', [VerificationController::class, 'send'])->middleware('throttle:6,1')->name('verification.send');
});

Route::middleware(['auth', 'verified:finance.verification.notice'])->prefix('app')->group(function () {
    Route::get('/carteiras', [WalletController::class, 'choose'])->name('wallets.choose');
    Route::post('/carteiras', [WalletController::class, 'switch'])->name('wallets.switch');
    Route::get('/nova-carteira', [WalletController::class, 'create'])->name('wallets.new');
    Route::post('/nova-carteira', [WalletController::class, 'store'])->middleware('throttle:5,1')->name('wallets.create');

    Route::middleware('finance.wallet')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // Pessoas na carteira (casal, família, sócios)
        Route::middleware('finance.can:finance.people.view')->get('/pessoas', [PeopleController::class, 'index'])->name('people');
        Route::middleware('finance.can:finance.people.manage')->group(function () {
            Route::post('/pessoas/convites', [PeopleController::class, 'invite'])->middleware('throttle:20,1')->name('people.invite');
            Route::post('/pessoas/convites/{invitation}/cancelar', [PeopleController::class, 'cancelInvitation'])->name('people.invitations.cancel');
            Route::post('/pessoas/{membership}/papel', [PeopleController::class, 'changeRole'])->name('people.role');
            Route::post('/pessoas/{membership}/suspender', [PeopleController::class, 'suspend'])->name('people.suspend');
            Route::post('/pessoas/{membership}/retirar', [PeopleController::class, 'remove'])->name('people.remove');
        });

        // A carteira atual: nome, tipo, MEI (só o dono)
        Route::middleware('finance.can:finance.wallet.manage')->group(function () {
            Route::get('/carteira', [WalletController::class, 'edit'])->name('wallet.settings');
            Route::post('/carteira', [WalletController::class, 'update'])->name('wallet.update');
        });

        // Meu perfil (qualquer pessoa): nome, e-mail e senha.
        Route::get('/perfil', [ProfileController::class, 'show'])->name('profile');
        Route::post('/perfil', [ProfileController::class, 'update'])->middleware('throttle:10,1')->name('profile.update');
        Route::post('/perfil/senha', [ProfileController::class, 'password'])->middleware('throttle:6,1')->name('profile.password');
    });
});
