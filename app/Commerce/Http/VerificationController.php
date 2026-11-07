<?php

namespace App\Commerce\Http;

use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Confirmação do e-mail de quem se cadastrou (convidados já confirmam ao aceitar o convite).
 */
class VerificationController
{
    public function notice(Request $request): View|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('commerce.dashboard');
        }

        return view('commerce.auth.verify');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->route('commerce.dashboard')->with('status', 'E-mail confirmado.');
    }

    public function send(Request $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('status', 'Enviamos um novo link para o seu e-mail.');
    }
}
