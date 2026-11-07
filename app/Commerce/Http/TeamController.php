<?php

namespace App\Commerce\Http;

use App\Commerce\Access;
use App\Commerce\Accounts\AccountSync;
use App\Commerce\Team\InvitationNotification;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Invitation;
use App\Platform\Memberships\Membership;
use App\Platform\Organizations\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Equipe da loja: um login por funcionário, convidado pelo dono com um papel (docs/09, F1).
 * Membership e Invitation não têm escopo global: tudo aqui filtra pela organização do contexto.
 */
class TeamController
{
    public function index(Request $request): View
    {
        $organization = $this->organization();

        return view('commerce.team', [
            'members' => Membership::with('user')->where('organization_id', $organization->getKey())->active()->oldest()->get(),
            'invitations' => Invitation::where('organization_id', $organization->getKey())->whereNull('accepted_at')->where('expires_at', '>', now())->latest()->get(),
            'roles' => Access::roles(),
            'canManage' => Access::allows($request->attributes->get('commerce.membership'), 'commerce.team.manage'),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $organization = $this->organization();
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(array_keys(Access::roles()))],
        ], [], ['role' => 'papel']);
        $email = Str::lower($data['email']);

        $alreadyMember = Membership::active()->where('organization_id', $organization->getKey())
            ->whereHas('user', fn ($q) => $q->where('email', $email))->exists();
        if ($alreadyMember) {
            throw ValidationException::withMessages(['email' => 'Essa pessoa já está na equipe.']);
        }

        $days = config('commerce.invitation_days');
        [$invitation, $token] = Invitation::issue($organization, $email, $data['role'], $request->user(), $days);
        $store = $request->attributes->get('commerce.store');

        Notification::route('mail', $email)->notify(new InvitationNotification(
            $store->name, $data['role'], $request->user()->name, route('commerce.invitation.show', $token), $days,
        ));
        Audit::record('commerce.team.invited', subject: $invitation, metadata: ['role' => $data['role']]);

        return back()->with('status', "Convite enviado para {$email}.");
    }

    public function cancelInvitation(string $invitation): RedirectResponse
    {
        $invitation = Invitation::where('organization_id', $this->organization()->getKey())->whereNull('accepted_at')->findOrFail($invitation);
        $invitation->delete();
        Audit::record('commerce.team.invitation_canceled', subject: $invitation);

        return back()->with('status', 'Convite cancelado.');
    }

    public function changeRole(Request $request, string $membership): RedirectResponse
    {
        $membership = $this->member($membership);
        $data = $request->validate(['role' => ['required', Rule::in(array_keys(Access::roles()))]], [], ['role' => 'papel']);

        if ($membership->role === 'owner' && $data['role'] !== 'owner') {
            $this->keepAnOwner($membership);
        }

        $from = $membership->role;
        $membership->update(['role' => $data['role']]);
        Audit::record('commerce.team.role_changed', subject: $membership, metadata: ['from' => $from, 'to' => $data['role']]);

        return back()->with('status', 'Papel alterado.');
    }

    public function remove(string $membership): RedirectResponse
    {
        $membership = $this->member($membership);

        if ($membership->role === 'owner') {
            $this->keepAnOwner($membership);
        }

        $membership->update(['status' => Membership::REMOVED]);
        Audit::record('commerce.team.removed', subject: $membership);
        AccountSync::sync($this->organization());

        return back()->with('status', 'Acesso retirado. A pessoa não entra mais nesta loja.');
    }

    private function organization(): Organization
    {
        return ExecutionContext::current()->organization;
    }

    private function member(string $id): Membership
    {
        return Membership::active()->where('organization_id', $this->organization()->getKey())->findOrFail($id);
    }

    /** A loja nunca fica sem dono. */
    private function keepAnOwner(Membership $membership): void
    {
        $owners = Membership::active()->where('organization_id', $membership->organization_id)->where('role', 'owner')->count();

        if ($owners <= 1) {
            throw ValidationException::withMessages(['role' => 'A loja precisa ter pelo menos um(a) dono(a). Passe o papel de dono(a) para outra pessoa antes.']);
        }
    }
}
