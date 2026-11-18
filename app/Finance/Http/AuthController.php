<?php

namespace App\Finance\Http;

use App\Finance\Http\Middleware\ResolveWallet;
use App\Finance\Wallets\OpenWallet;
use App\Finance\Wallets\Wallet;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Login no Core pelo endereço do Finanças (mesmo login único do Commerce). O cadastro já cria a carteira
 * pessoal e, se a pessoa marcar, a do negócio (docs/11, D-F2).
 */
class AuthController
{
    public function showLogin(): View
    {
        return view('finance.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('finance.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('finance.login');
    }

    public function showSignup(): View
    {
        return view('finance.auth.signup', ['open' => config('finance.signup_open')]);
    }

    public function signup(Request $request, OpenWallet $wallets): RedirectResponse
    {
        abort_unless(config('finance.signup_open'), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
            'has_business' => ['nullable', 'boolean'],
            'business_name' => ['nullable', 'required_if:has_business,1', 'string', 'min:2', 'max:80'],
            'is_mei' => ['nullable', 'boolean'],
        ], [], ['name' => 'nome', 'password' => 'senha', 'business_name' => 'nome do negócio']);

        $user = DB::transaction(function () use ($data, $wallets, $request) {
            $user = User::create(['name' => $data['name'], 'email' => Str::lower($data['email']), 'password' => $data['password']]);
            $personal = $wallets->open($user, 'Pessoal');
            if ($request->boolean('has_business')) {
                $wallets->open($user, trim($data['business_name']), Wallet::BUSINESS, $request->boolean('is_mei'));
            }
            $request->session()->put(ResolveWallet::SESSION_KEY, $personal->organization_id);

            return $user;
        });

        $user->sendEmailVerificationNotification();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('finance.verification.notice');
    }

    public function showForgot(): View
    {
        return view('finance.auth.forgot');
    }

    public function sendReset(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => Str::lower($request->input('email'))]);

        // Mesma resposta exista ou não a conta (não revela quem tem cadastro).
        return back()->with('status', 'Se houver uma conta com esse e-mail, enviamos o link para criar uma nova senha.');
    }

    public function showReset(Request $request, string $token): View
    {
        return view('finance.auth.reset', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ], [], ['password' => 'senha']);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'O link venceu ou não é válido. Peça um novo.']);
        }

        return redirect()->route('finance.login')->with('status', 'Senha nova criada. Já pode entrar.');
    }
}
