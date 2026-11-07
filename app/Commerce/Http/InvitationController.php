<?php

namespace App\Commerce\Http;

use App\Commerce\Access;
use App\Commerce\Accounts\AccountSync;
use App\Commerce\Http\Middleware\ResolveStore;
use App\Commerce\Stores\Store;
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
 * Link do convite (público; o token é a prova). Quem ainda não tem login cria o seu; quem já tem entra com ele.
 */
class InvitationController
{
    public function show(Request $request, string $token): View
    {
        $invitation = Invitation::findPending($token);

        if (! $invitation) {
            return view('commerce.invitation', ['invitation' => null]);
        }

        return view('commerce.invitation', [
            'invitation' => $invitation,
            'token' => $token,
            'storeName' => $this->storeName($invitation),
            'role' => Access::label($invitation->role),
            'existingUser' => User::where('email', $invitation->email)->exists(),
            'user' => $request->user(),
        ]);
    }

    public function accept(Request $request, ContextStore $contexts, string $token): RedirectResponse
    {
        $invitation = Invitation::findPending($token);
        abort_unless($invitation, 404);

        $user = $request->user();
        $existing = User::where('email', $invitation->email)->first();
        $data = [];

        if ($user && strtolower($user->email) !== $invitation->email) {
            return back()->withErrors(['email' => "Este convite é para {$invitation->email}. Saia e entre com essa conta."]);
        }

        if (! $user && $existing) {
            $request->session()->put('url.intended', route('commerce.invitation.show', $token));

            return redirect()->route('commerce.login')->with('status', 'Entre com a sua senha para aceitar o convite.');
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
                Audit::record('commerce.team.joined', subject: $membership, metadata: ['role' => $invitation->role]);
            });
            AccountSync::sync($invitation->organization);

            return $user;
        });

        if (! $request->user()) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        $request->session()->put(ResolveStore::SESSION_KEY, $invitation->organization_id);

        return redirect()->route('commerce.dashboard')->with('status', 'Bem-vindo(a) à equipe da '.$this->storeName($invitation).'.');
    }

    private function storeName(Invitation $invitation): string
    {
        return Store::withoutGlobalScopes()->where('organization_id', $invitation->organization_id)->value('name') ?? $invitation->organization->name;
    }
}
