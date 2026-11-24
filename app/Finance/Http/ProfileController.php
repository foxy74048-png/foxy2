<?php

namespace App\Finance\Http;

use App\Commerce\Team\AccountChangedNotification;
use App\Finance\Access;
use App\Finance\Http\Middleware\ResolveWallet;
use App\Finance\WhatsApp\FinanceWhatsApp;
use App\Finance\WhatsApp\PhoneLinks;
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
 * Meu perfil: nome, e-mail e senha da própria conta (o login é o mesmo em todos os produtos Foxy), e as
 * carteiras em que a pessoa está. Trocar e-mail ou senha pede a senha atual; o antigo recebe um aviso.
 */
class ProfileController
{
    public function __construct(private PhoneLinks $links) {}

    public function show(Request $request): View
    {
        $options = ResolveWallet::walletsOf(ResolveWallet::membershipsOf($request->user()->getKey()));

        return view('finance.profile', [
            'user' => $request->user(),
            'wallets' => $options->map(fn ($o) => ['wallet' => $o['wallet'], 'role' => Access::label($o['membership']->role), 'organization_id' => $o['membership']->organization_id]),
            'phoneLink' => $this->links->of($request->user()),
            'whatsappAvailable' => $this->links->isAvailable(),
        ]);
    }

    /** WhatsApp (fatia 9): manda o código para o número informado. */
    public function whatsappStart(Request $request): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']], [], ['phone' => 'número']);
        if (! $this->links->isAvailable()) {
            return back()->withErrors(['phone' => 'O WhatsApp do Foxy Finanças ainda não está ligado.']);
        }
        $this->links->start($request->user(), $data['phone']);

        return back()->with('status', 'Mandamos um código para o seu WhatsApp. Digite abaixo.');
    }

    public function whatsappVerify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']], [], ['code' => 'código']);
        $link = $this->links->verify($request->user(), $data['code']);
        $link->forceFill(['default_organization_id' => $link->default_organization_id ?? $request->attributes->get('finance.membership')?->organization_id])->save();
        app(\App\Platform\Messaging\WhatsAppConnections::class)->send(PhoneLinks::instance(), $link->phone, FinanceWhatsApp::help());

        return back()->with('status', 'Pronto! Este WhatsApp está ligado à sua conta. Mandamos uma mensagem com exemplos.');
    }

    public function whatsappWallet(Request $request): RedirectResponse
    {
        $ids = ResolveWallet::membershipsOf($request->user()->getKey())->pluck('organization_id')->all();
        $data = $request->validate(['organization_id' => ['required', 'string', Rule::in($ids)]], [], ['organization_id' => 'carteira']);
        $link = $this->links->of($request->user());
        abort_unless($link?->isVerified(), 404);
        $link->forceFill(['default_organization_id' => $data['organization_id']])->save();

        return back()->with('status', 'Os lançamentos pelo WhatsApp vão para essa carteira.');
    }

    /** Lembrete semanal dos desafios do mês pelo WhatsApp (só para quem ligar). */
    public function whatsappReminders(Request $request): RedirectResponse
    {
        $link = $this->links->of($request->user());
        abort_unless($link?->isVerified(), 404);
        $link->forceFill(['challenge_reminders' => $request->boolean('on')])->save();

        return back()->with('status', $link->challenge_reminders ? 'Pronto! Todo domingo mandamos como estão os seus desafios do mês.' : 'Lembrete dos desafios desligado.');
    }

    public function whatsappUnlink(Request $request): RedirectResponse
    {
        $this->links->unlink($request->user());

        return back()->with('status', 'WhatsApp desligado da sua conta. As mensagens desse número não lançam mais nada.');
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
        Audit::record('finance.profile.updated', subject: $user, metadata: ['email_changed' => $emailChanged]);

        if ($emailChanged) {
            Notification::route('mail', $old)->notify(new AccountChangedNotification('email', $email));
            $user->sendEmailVerificationNotification();

            return redirect()->route('finance.verification.notice')->with('status', 'E-mail trocado. Confirme o novo endereço pelo link que enviamos para '.$email.'.');
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
        Audit::record('finance.profile.password', subject: $user);
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
