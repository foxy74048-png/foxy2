<?php

namespace App\Commerce\Http;

use App\Commerce\Stores\OpenStore;
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
 * Login no Core pelo endereço do Commerce: cada pessoa tem o seu (docs/09, F1).
 */
class AuthController
{
    public function showLogin(): View
    {
        return view('commerce.auth.login');
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

        return redirect()->intended(route('commerce.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('commerce.login');
    }

    public function showSignup(): View
    {
        return view('commerce.auth.signup', ['open' => config('commerce.signup_open')]);
    }

    public function signup(Request $request, OpenStore $stores): RedirectResponse
    {
        abort_unless(config('commerce.signup_open'), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
            'store_name' => ['required', 'string', 'min:2', 'max:80'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
        ], [], ['name' => 'nome', 'password' => 'senha', 'store_name' => 'nome da loja']);

        $user = DB::transaction(function () use ($data, $stores) {
            $user = User::create(['name' => $data['name'], 'email' => Str::lower($data['email']), 'password' => $data['password']]);
            $stores->open($user, $data['store_name'], $data['whatsapp'] ?? null);

            return $user;
        });

        $user->sendEmailVerificationNotification();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('commerce.verification.notice');
    }

    public function showForgot(): View
    {
        return view('commerce.auth.forgot');
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
        return view('commerce.auth.reset', ['token' => $token, 'email' => $request->query('email')]);
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

        return redirect()->route('commerce.login')->with('status', 'Senha nova criada. Já pode entrar.');
    }
}
