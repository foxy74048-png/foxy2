<?php

namespace App\Finance\Http;

use App\Finance\Access;
use App\Finance\Accounts\AccountSync;
use App\Finance\Insights\Export;
use App\Finance\Wallets\InvitationNotification;
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
use Symfony\Component\HttpFoundation\Response;

/**
 * Pessoas na carteira: casal, família ou sócios, cada um com o próprio login, convidados pelo dono com um
 * papel. Membership e Invitation não têm escopo global: tudo aqui filtra pela organização do contexto.
 */
class PeopleController
{
    public function index(Request $request): View|Response
    {
        $organization = $this->organization();
        $members = Membership::with('user')->where('organization_id', $organization->getKey())->onTeam()->oldest()->get();

        if ($format = Export::requested($request)) {
            $status = [Membership::ACTIVE => 'Ativo', Membership::SUSPENDED => 'Suspenso'];

            return (new Export('Pessoas na carteira'))
                ->section(null, ['Nome' => 'text', 'E-mail' => 'text', 'Papel' => 'text', 'Situação' => 'text', 'Desde' => 'date'],
                    $members->map(fn (Membership $m) => [$m->user->name, $m->user->email, Access::label($m->role), $status[$m->status] ?? $m->status, $m->created_at]))
                ->download($format);
        }

        return view('finance.people', [
            'members' => $members,
            'invitations' => Invitation::where('organization_id', $organization->getKey())->whereNull('accepted_at')->where('expires_at', '>', now())->latest()->get(),
            'roles' => Access::roles(),
            'canManage' => Access::allows($request->attributes->get('finance.membership'), 'finance.people.manage'),
            'maxPeople' => config('finance.max_people'),
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

        $alreadyMember = Membership::onTeam()->where('organization_id', $organization->getKey())
            ->whereHas('user', fn ($q) => $q->where('email', $email))->exists();
        if ($alreadyMember) {
            throw ValidationException::withMessages(['email' => 'Essa pessoa já está na carteira (se estiver suspensa, é só reativar na lista).']);
        }

        // Limite: quem já está + convites esperando resposta (menos um reenvio para o mesmo e-mail).
        $limit = config('finance.max_people');
        $taken = Membership::onTeam()->where('organization_id', $organization->getKey())->count()
            + Invitation::where('organization_id', $organization->getKey())->whereNull('accepted_at')
                ->where('expires_at', '>', now())->where('email', '!=', $email)->count();
        if ($limit !== null && $taken >= $limit) {
            throw ValidationException::withMessages(['email' => "A carteira pode ter até {$limit} pessoas (contando os convites pendentes)."]);
        }

        $days = config('finance.invitation_days');
        [$invitation, $token] = Invitation::issue($organization, $email, $data['role'], $request->user(), $days);

        Notification::route('mail', $email)->notify(new InvitationNotification(
            $request->attributes->get('finance.wallet')->name, $data['role'], $request->user()->name, route('finance.invitation.show', $token), $days,
        ));
        Audit::record('finance.people.invited', subject: $invitation, metadata: ['role' => $data['role']]);

        return back()->with('status', "Convite enviado para {$email}.");
    }

    public function cancelInvitation(string $invitation): RedirectResponse
    {
        $invitation = Invitation::where('organization_id', $this->organization()->getKey())->whereNull('accepted_at')->findOrFail($invitation);
        $invitation->delete();
        Audit::record('finance.people.invitation_canceled', subject: $invitation);

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
        Audit::record('finance.people.role_changed', subject: $membership, metadata: ['from' => $from, 'to' => $data['role']]);

        return back()->with('status', 'Papel alterado.');
    }

    public function remove(string $membership): RedirectResponse
    {
        $membership = $this->member($membership);

        if ($membership->role === 'owner') {
            $this->keepAnOwner($membership);
        }

        $membership->update(['status' => Membership::REMOVED]);
        Audit::record('finance.people.removed', subject: $membership);
        AccountSync::sync($this->organization());

        return back()->with('status', 'Acesso retirado. A pessoa não vê mais esta carteira.');
    }

    /** Suspende ou reativa o acesso sem tirar a pessoa da carteira. */
    public function suspend(Request $request, string $membership): RedirectResponse
    {
        $membership = $this->member($membership);
        $suspend = $request->boolean('suspend');

        if ($suspend) {
            if ((string) $membership->user_id === (string) $request->user()->getKey()) {
                throw ValidationException::withMessages(['role' => 'Você não pode suspender o seu próprio acesso.']);
            }
            if ($membership->role === 'owner') {
                $this->keepAnOwner($membership);
            }
        }

        $membership->update(['status' => $suspend ? Membership::SUSPENDED : Membership::ACTIVE]);
        Audit::record($suspend ? 'finance.people.suspended' : 'finance.people.reactivated', subject: $membership);
        AccountSync::sync($this->organization());

        return back()->with('status', $suspend ? 'Acesso suspenso. A pessoa continua na lista, mas não entra até ser reativada.' : 'Acesso reativado.');
    }

    private function organization(): Organization
    {
        return ExecutionContext::current()->organization;
    }

    private function member(string $id): Membership
    {
        return Membership::onTeam()->where('organization_id', $this->organization()->getKey())->findOrFail($id);
    }

    /** A carteira nunca fica sem dono. */
    private function keepAnOwner(Membership $membership): void
    {
        $owners = Membership::active()->where('organization_id', $membership->organization_id)->where('role', 'owner')->count();

        if ($owners <= 1) {
            throw ValidationException::withMessages(['role' => 'A carteira precisa ter pelo menos um(a) dono(a). Passe o papel de dono(a) para outra pessoa antes.']);
        }
    }
}
