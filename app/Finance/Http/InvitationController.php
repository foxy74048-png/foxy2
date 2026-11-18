<?php

namespace App\Finance\Http;

use App\Finance\Access;
use App\Finance\Accounts\AccountSync;
use App\Finance\Http\Middleware\ResolveWallet;
use App\Finance\Wallets\Wallet;
use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Invitation;
use App\Platform\Memberships\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * Link do convite para uma carteira (público; o token é a prova). Só vale para convites de carteiras do
 * Finanças. Quem ainda não tem login cria o seu; quem já tem (inclusive do Commerce) entra com ele.
 */
class InvitationController
{
    public function show(Request $request, string $token): View
    {
        $invitation = $this->pending($token);

        if (! $invitation) {
            return view('finance.invitation', ['invitation' => null]);
        }

        return view('finance.invitation', [
            'invitation' => $invitation,
            'token' => $token,
            'walletName' => $this->walletName($invitation),
            'role' => Access::label($invitation->role),
            'existingUser' => User::where('email', $invitation->email)->exists(),
            'user' => $request->user(),
        ]);
    }

    public function accept(Request $request, ContextStore $contexts, string $token): RedirectResponse
    {
        $invitation = $this->pending($token);
        abort_unless($invitation, 404);

        $user = $request->user();
        $existing = User::where('email', $invitation->email)->first();
        $data = [];

        if ($user && strtolower($user->email) !== $invitation->email) {
            return back()->withErrors(['email' => "Este convite é para {$invitation->email}. Saia e entre com essa conta."]);
        }

        if (! $user && $existing) {
            $request->session()->put('url.intended', route('finance.invitation.show', $token));

            return redirect()->route('finance.login')->with('status', 'Entre com a sua senha para aceitar o convite.');
        }

        if (! $user) {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'password' => ['required', 'confirmed', PasswordRule::min(8)],
            ], [], ['name' => 'nome', 'password' => 'senha']);
        }

        $user = DB::transaction(function () use ($invitation, $user, $data, $contexts) {
            $user ??= User::create(['name' => $data['name'], 'email' => $invitation->email, 'password' => $data['password']]);

            // O convite chegou no e-mail: ele está confirmado.
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            $membership = Membership::updateOrCreate(
                ['organization_id' => $invitation->organization_id, 'user_id' => $user->getKey()],
                ['role' => $invitation->role, 'status' => Membership::ACTIVE, 'invited_by' => $invitation->invited_by],
            );
            $invitation->update(['accepted_at' => now()]);

            $contexts->run(ExecutionContext::forUser($invitation->organization, $user->getKey()), function () use ($membership, $invitation) {
                Audit::record('finance.people.joined', subject: $membership, metadata: ['role' => $invitation->role]);
            });
            AccountSync::sync($invitation->organization);

            return $user;
        });

        if (! $request->user()) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        $request->session()->put(ResolveWallet::SESSION_KEY, $invitation->organization_id);

        return redirect()->route('finance.dashboard')->with('status', 'Pronto: você está na carteira "'.$this->walletName($invitation).'".');
    }

    /** Convite pendente de uma carteira do Finanças (o link do Commerce não serve aqui, nem o contrário). */
    private function pending(string $token): ?Invitation
    {
        $invitation = Invitation::findPending($token);

        return $invitation && Wallet::withoutGlobalScopes()->where('organization_id', $invitation->organization_id)->exists() ? $invitation : null;
    }

    private function walletName(Invitation $invitation): string
    {
        return Wallet::withoutGlobalScopes()->where('organization_id', $invitation->organization_id)->value('name') ?? $invitation->organization->name;
    }
}
