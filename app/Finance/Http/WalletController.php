<?php

namespace App\Finance\Http;

use App\Finance\Access;
use App\Finance\Accounts\AccountSync;
use App\Finance\Http\Middleware\ResolveWallet;
use App\Finance\Insights\Export;
use App\Finance\Privacy\WalletArchive;
use App\Finance\Privacy\WalletEraser;
use App\Finance\Wallets\OpenWallet;
use App\Finance\Wallets\Wallet;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Carteiras: escolher em qual está, abrir outra (a do negócio, a da casa) e ajustar a atual.
 */
class WalletController
{
    public function choose(Request $request): View|RedirectResponse
    {
        $memberships = ResolveWallet::membershipsOf($request->user()->getKey());

        if ($memberships->isEmpty()) {
            return redirect()->route('finance.wallets.new');
        }

        return view('finance.wallets.choose', [
            'options' => ResolveWallet::walletsOf($memberships)->map(fn ($o) => $o + ['role' => Access::label($o['membership']->role)]),
        ]);
    }

    public function switch(Request $request): RedirectResponse
    {
        $data = $request->validate(['organization_id' => ['required', 'string']]);
        $membership = ResolveWallet::membershipsOf($request->user()->getKey())->firstWhere('organization_id', $data['organization_id']);
        abort_unless($membership, 404);

        $request->session()->put(ResolveWallet::SESSION_KEY, $membership->organization_id);

        return redirect()->route('finance.dashboard');
    }

    public function create(Request $request): View
    {
        return view('finance.wallets.new', [
            'hasWallets' => ResolveWallet::membershipsOf($request->user()->getKey())->isNotEmpty(),
            'canOpen' => $this->canOpen($request),
        ]);
    }

    public function store(Request $request, OpenWallet $wallets): RedirectResponse
    {
        abort_unless($this->canOpen($request), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'kind' => ['required', Rule::in(array_keys(config('finance.wallet_kinds')))],
            'is_mei' => ['nullable', 'boolean'],
        ], [], ['name' => 'nome da carteira', 'kind' => 'tipo']);

        $max = config('finance.max_owned_wallets');
        if (OpenWallet::ownedBy($request->user()) >= $max) {
            throw ValidationException::withMessages(['name' => "Você já tem {$max} carteiras. Para abrir outra, fale com o suporte."]);
        }

        $wallet = $wallets->open($request->user(), trim($data['name']), $data['kind'], $request->boolean('is_mei'));
        $request->session()->put(ResolveWallet::SESSION_KEY, $wallet->organization_id);

        return redirect()->route('finance.dashboard')->with('status', "Carteira \"{$wallet->name}\" criada. Você já está nela.");
    }

    public function edit(Request $request): View
    {
        return view('finance.wallets.settings', [
            'wallet' => $request->attributes->get('finance.wallet'),
            'kinds' => config('finance.wallet_kinds'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var Wallet $wallet */
        $wallet = $request->attributes->get('finance.wallet');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'kind' => ['required', Rule::in(array_keys(config('finance.wallet_kinds')))],
            'is_mei' => ['nullable', 'boolean'],
            'das_day' => ['nullable', 'integer', 'between:1,28'],
        ], [], ['name' => 'nome da carteira', 'kind' => 'tipo', 'das_day' => 'dia do DAS']);

        $business = $data['kind'] === Wallet::BUSINESS;
        $wallet->update([
            'name' => trim($data['name']),
            'kind' => $data['kind'],
            'is_mei' => $business && $request->boolean('is_mei'),
            'das_day' => $business && $request->boolean('is_mei') ? ($data['das_day'] ?? 20) : null,
        ]);
        Audit::record('finance.wallet.updated', subject: $wallet);
        AccountSync::sync(ExecutionContext::current()->organization);

        return back()->with('status', 'Carteira salva.');
    }

    /** Exportar tudo da carteira (fatia 11): planilha ou PDF com todas as seções. */
    public function export(Request $request, WalletArchive $archive): Response
    {
        $format = Export::requested($request) ?? 'planilha';
        Audit::record('finance.wallet.exported', subject: $request->attributes->get('finance.wallet'));

        return $archive->build(ExecutionContext::current()->organization)->download($format);
    }

    /** Apagar a carteira de vez (fatia 11): só o dono, com a senha. Vale para todas as pessoas da carteira. */
    public function destroy(Request $request, WalletEraser $eraser): RedirectResponse
    {
        /** @var Wallet $wallet */
        $wallet = $request->attributes->get('finance.wallet');
        $request->validate([
            'password' => ['required', 'string'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Marque que entendeu que não tem volta.'], ['password' => 'senha']);
        if (! Hash::check((string) $request->input('password'), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'A senha não confere.']);
        }

        $name = $wallet->name;
        $organization = ExecutionContext::current()->organization;
        Audit::record('finance.wallet.deleted', subject: $wallet);
        $eraser->erase($organization);
        $request->session()->forget(ResolveWallet::SESSION_KEY);

        return redirect()->route('finance.wallets.choose')->with('status', "A carteira \"{$name}\" e tudo o que ela tinha foram apagados.");
    }

    /** Antes do lançamento (cadastro fechado), só quem já tem uma carteira sua ou é da equipe Foxy abre outra. */
    private function canOpen(Request $request): bool
    {
        return config('finance.signup_open') || $request->user()->isStaff() || OpenWallet::ownedBy($request->user()) > 0;
    }
}
