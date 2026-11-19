<?php

use App\Finance\Http\AccountController;
use App\Finance\Http\AuthController;
use App\Finance\Http\BudgetController;
use App\Finance\Http\InsightsController;
use App\Finance\Http\CardController;
use App\Finance\Http\CategoryController;
use App\Finance\Http\DashboardController;
use App\Finance\Http\DebtController;
use App\Finance\Http\GoalController;
use App\Finance\Http\InvitationController;
use App\Finance\Http\PeopleController;
use App\Finance\Http\ProfileController;
use App\Finance\Http\StatementController;
use App\Finance\Http\StatementLineController;
use App\Finance\Http\TransactionController;
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

        // Lançamentos (fatia 2): ver é de todos; lançar, mudar e apagar pede permissão.
        // Onde vai o dinheiro: indicadores para economizar (fatia 6)
        Route::middleware('finance.can:finance.reports.view')->get('/painel', [InsightsController::class, 'index'])->name('insights');

        Route::middleware('finance.can:finance.view')->group(function () {
            Route::get('/lancamentos', [TransactionController::class, 'index'])->name('transactions');
            Route::get('/contas', [AccountController::class, 'index'])->name('accounts');
            // Orçamento (fatia 6) e metas (6b)
            Route::get('/orcamento', [BudgetController::class, 'index'])->name('budgets');
            Route::get('/metas', [GoalController::class, 'index'])->name('goals');
            // Quem me deve (6c)
            Route::get('/quem-me-deve', [DebtController::class, 'index'])->name('debts');
            Route::get('/quem-me-deve/{contact}', [DebtController::class, 'show'])->name('debts.show');
            // Cartões e faturas (fatia 5)
            Route::get('/cartoes', [CardController::class, 'index'])->name('cards');
            Route::get('/cartoes/{account}', [CardController::class, 'show'])->name('cards.show');
            Route::get('/categorias', [CategoryController::class, 'index'])->name('categories');
        });
        Route::middleware('finance.can:finance.transactions.manage')->group(function () {
            Route::post('/lancamentos', [TransactionController::class, 'store'])->middleware('throttle:60,1')->name('transactions.store');
            Route::get('/lancamentos/{transaction}', [TransactionController::class, 'edit'])->name('transactions.edit');
            Route::post('/lancamentos/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
            Route::post('/lancamentos/{transaction}/apagar', [TransactionController::class, 'destroy'])->name('transactions.destroy');
            Route::post('/lancamentos/{transaction}/confirmar', [TransactionController::class, 'confirm'])->name('transactions.confirm');
            Route::post('/cartoes/{account}/pagar', [CardController::class, 'pay'])->middleware('throttle:30,1')->name('cards.pay');
            Route::post('/quem-me-deve', [DebtController::class, 'store'])->middleware('throttle:60,1')->name('debts.store');
            Route::post('/quem-me-deve/dividir', [DebtController::class, 'split'])->middleware('throttle:30,1')->name('debts.split');
            Route::post('/quem-me-deve/objeto', [DebtController::class, 'object'])->middleware('throttle:30,1')->name('debts.object');
            Route::post('/quem-me-deve/movimentos/{entry}/devolvido', [DebtController::class, 'returnObject'])->name('debts.return');
            Route::post('/quem-me-deve/movimentos/{entry}/apagar', [DebtController::class, 'destroyEntry'])->name('debts.entry.destroy');
            Route::post('/quem-me-deve/{contact}', [DebtController::class, 'updateContact'])->name('debts.contact.update');
            Route::post('/quem-me-deve/{contact}/arquivar', [DebtController::class, 'archiveContact'])->name('debts.contact.archive');
            Route::post('/metas', [GoalController::class, 'store'])->middleware('throttle:30,1')->name('goals.store');
            Route::post('/metas/{goal}', [GoalController::class, 'update'])->name('goals.update');
            Route::post('/metas/{goal}/arquivar', [GoalController::class, 'archive'])->name('goals.archive');
            Route::post('/metas/{goal}/apagar', [GoalController::class, 'destroy'])->name('goals.destroy');
            Route::post('/metas/{goal}/entradas', [GoalController::class, 'entry'])->middleware('throttle:60,1')->name('goals.entry');
            Route::post('/metas/entradas/{entry}/apagar', [GoalController::class, 'destroyEntry'])->name('goals.entry.destroy');
        });
        // Importar extrato, fatura ou planilha antiga e revisar linha a linha (fatia 3).
        Route::middleware('finance.can:finance.imports.manage')->prefix('extratos')->name('statements')->group(function () {
            Route::get('/', [StatementController::class, 'index']);
            Route::post('/', [StatementController::class, 'upload'])->middleware('throttle:20,1')->name('.upload');
            Route::get('/colunas', [StatementController::class, 'mapping'])->name('.mapping');
            Route::post('/colunas', [StatementController::class, 'import'])->middleware('throttle:20,1')->name('.import');
            Route::post('/colunas/cancelar', [StatementController::class, 'cancel'])->name('.cancel');
            Route::post('/importacoes/{import}/desfazer', [StatementController::class, 'undo'])->name('.undo');
            Route::post('/limpar', [StatementController::class, 'clear'])->name('.clear');
            Route::post('/lote', [StatementLineController::class, 'bulk'])->middleware('throttle:30,1')->name('.bulk');
            Route::post('/linhas/{line}/lancar', [StatementLineController::class, 'create'])->name('.create');
            Route::post('/linhas/{line}/conciliar', [StatementLineController::class, 'match'])->name('.match');
            Route::post('/linhas/{line}/dividir', [StatementLineController::class, 'split'])->name('.split');
            Route::post('/linhas/{line}/ignorar', [StatementLineController::class, 'ignore'])->name('.ignore');
            Route::post('/linhas/{line}/desfazer', [StatementLineController::class, 'undo'])->name('.undo-line');
            Route::post('/linhas/{line}/mostrar', [StatementController::class, 'unhide'])->name('.unhide');
        });

        Route::middleware('finance.can:finance.accounts.manage')->group(function () {
            Route::post('/contas', [AccountController::class, 'store'])->middleware('throttle:30,1')->name('accounts.store');
            Route::post('/contas/{account}', [AccountController::class, 'update'])->name('accounts.update');
            Route::post('/contas/{account}/arquivar', [AccountController::class, 'archive'])->name('accounts.archive');
            Route::post('/contas/{account}/excluir', [AccountController::class, 'destroy'])->name('accounts.destroy');
            Route::post('/categorias', [CategoryController::class, 'store'])->middleware('throttle:30,1')->name('categories.store');
            Route::post('/categorias/{category}', [CategoryController::class, 'update'])->name('categories.update');
            Route::post('/categorias/{category}/esconder', [CategoryController::class, 'hide'])->name('categories.hide');
            Route::post('/categorias/{category}/juntar', [CategoryController::class, 'merge'])->name('categories.merge');
            Route::post('/orcamento', [BudgetController::class, 'store'])->middleware('throttle:30,1')->name('budgets.store');
            Route::post('/orcamento/{budget}', [BudgetController::class, 'update'])->name('budgets.update');
            Route::post('/orcamento/{budget}/apagar', [BudgetController::class, 'destroy'])->name('budgets.destroy');
            // Regras automáticas (fatia 4)
            Route::post('/regras', [CategoryController::class, 'storeRule'])->middleware('throttle:30,1')->name('rules.store');
            Route::post('/regras/{rule}', [CategoryController::class, 'updateRule'])->name('rules.update');
            Route::post('/regras/{rule}/apagar', [CategoryController::class, 'destroyRule'])->name('rules.destroy');
        });

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
