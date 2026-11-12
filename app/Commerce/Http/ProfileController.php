<?php

namespace App\Commerce\Http;

use App\Commerce\Access;
use App\Commerce\Http\Middleware\ResolveStore;
use App\Commerce\Stores\Store;
use App\Commerce\Team\AccountChangedNotification;
use App\Platform\Audit\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Meu perfil (qualquer pessoa da equipe): nome, e-mail e senha da própria conta, e as lojas em que ela está.
 * Trocar e-mail ou senha pede a senha atual; e-mail novo precisa ser confirmado e o antigo recebe um aviso.
 */
class ProfileController
{
    public function show(Request $request): View
    {
        $memberships = ResolveStore::membershipsOf($request->user()->getKey());
        $stores = Store::withoutGlobalScopes()->whereIn('organization_id', $memberships->pluck('organization_id'))->pluck('name', 'organization_id');

        return view('commerce.profile', [
            'user' => $request->user(),
            'memberships' => $memberships->map(fn ($m) => ['store' => $stores->get($m->organization_id), 'role' => Access::label($m->role)]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'email_password' => ['nullable', 'string'],
        ], [], ['name' => 'nome', 'email' => 'e-mail', 'email_password' => 'senha atual']);

        $email = Str::lower(trim($data['email']));
        $emailChanged = $email !== Str::lower($user->email);
        if ($emailChanged) {
            $this->checkPassword($user, $data['email_password'] ?? null, 'email_password');
        }

        $old = $user->email;
        $user->name = trim($data['name']);
        if ($emailChanged) {
            $user->forceFill(['email' => $email, 'email_verified_at' => null]);
        }
        $user->save();
        Audit::record('commerce.profile.updated', subject: $user, metadata: ['email_changed' => $emailChanged]);

        if ($emailChanged) {
            Notification::route('mail', $old)->notify(new AccountChangedNotification('email', $email));
            $user->sendEmailVerificationNotification();

            return redirect()->route('commerce.verification.notice')->with('status', 'E-mail trocado. Confirme o novo endereço pelo link que enviamos para '.$email.'.');
        }

        return back()->with('status', 'Perfil salvo.');
    }

    public function password(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ], [], ['current_password' => 'senha atual', 'password' => 'senha nova']);
        $this->checkPassword($user, $data['current_password'], 'current_password');

        $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
        Auth::logoutOtherDevices($data['password']);
        $request->session()->regenerate();
        Audit::record('commerce.profile.password', subject: $user);
        $user->notify(new AccountChangedNotification('password'));

        return back()->with('status', 'Senha trocada. As outras sessões abertas (outros aparelhos) foram encerradas.');
    }

    private function checkPassword($user, ?string $password, string $field): void
    {
        if (! $password || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([$field => 'A senha atual não confere.']);
        }
    }
}
