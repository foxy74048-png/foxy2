{{-- Aviso da situação da assinatura, no topo das telas do app (depois de EnforceSubscription). --}}
@php($canBill = \App\Commerce\Access::allows($currentMembership, 'commerce.billing.manage'))
@if (session('status'))<div class="alert success" style="margin-bottom:16px;">{{ session('status') }}</div>@endif
@if ($errors->any())<div class="alert error" style="margin-bottom:16px;">{{ $errors->first() }}</div>@endif
@if ($readOnly)
    <div class="alert error" style="margin-bottom:16px;">
        A loja está <strong>só para consulta</strong>: o teste grátis acabou ou a assinatura foi cancelada. Nada foi apagado.
        @if ($canBill)<a href="{{ route('commerce.billing') }}">Assinar agora</a>@else Fale com o(a) dono(a) da loja.@endif
    </div>
@elseif ($currentSubscription?->isPastDue())
    <div class="alert error" style="margin-bottom:16px;">
        Não conseguimos cobrar a assinatura. O Stripe vai tentar de novo; atualize o cartão para não perder o acesso.
        @if ($canBill)<a href="{{ route('commerce.billing') }}">Atualizar pagamento</a>@endif
    </div>
@elseif ($currentSubscription?->inTrial() && ! $currentSubscription->isFounder() && $currentSubscription->trialDaysLeft() <= 3)
    <div class="alert info" style="margin-bottom:16px;">
        O teste grátis acaba em {{ $currentSubscription->trialDaysLeft() }} {{ $currentSubscription->trialDaysLeft() === 1 ? 'dia' : 'dias' }}.
        @if ($canBill)<a href="{{ route('commerce.billing') }}">Escolher um plano</a>@endif
    </div>
@endif
