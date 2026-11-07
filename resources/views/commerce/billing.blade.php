<x-core::layout title="Assinatura" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>

    @include('commerce.status')

    <div class="card">
        <h3 style="margin-top:0;">Situação</h3>
        @if ($subscription->isFounder())
            <p style="margin:0;"><span class="badge success">Parceiro Fundador</span> Uso completo, sem cobrança.</p>
        @elseif ($subscription->isPaying())
            <p style="margin:0;">
                <span @class(['badge', 'success' => ! $subscription->isPastDue(), 'danger' => $subscription->isPastDue()])>{{ $subscription->isPastDue() ? 'Pagamento atrasado' : 'Ativa' }}</span>
                Plano <strong>{{ \App\Commerce\Billing\Plans::name($subscription->plan) }}</strong>
                @if ($subscription->current_period_end)
                    · {{ $subscription->cancel_at_period_end ? 'termina em' : 'renova em' }} {{ $subscription->current_period_end->format('d/m/Y') }}
                @endif
            </p>
            @if ($subscription->trial_ends_at?->isFuture())<p class="helper">A primeira cobrança acontece quando o teste grátis acabar, em {{ $subscription->trial_ends_at->format('d/m/Y') }}.</p>@endif
        @elseif ($subscription->inTrial())
            <p style="margin:0;"><span class="badge">Teste grátis</span> até {{ $subscription->trial_ends_at->format('d/m/Y') }} ({{ $subscription->trialDaysLeft() }} {{ $subscription->trialDaysLeft() === 1 ? 'dia' : 'dias' }}). Assinando agora, a cobrança só começa quando o teste acabar.</p>
        @else
            <p style="margin:0;"><span class="badge danger">Sem assinatura</span> A loja está só para consulta até você assinar.</p>
        @endif

        @if ($subscription->stripe_customer_id)
            <form method="POST" action="{{ route('commerce.billing.portal') }}" style="margin-top:12px;">
                @csrf
                <button type="submit" class="secondary">Gerenciar assinatura (cartão, faturas, plano, cancelar)</button>
            </form>
        @endif
    </div>

    @unless ($subscription->isPaying() || $subscription->isFounder())
        <div class="grid cards" style="margin-top:16px;">
            @foreach ($plans as $key => $plan)
                <form class="card stack" method="POST" action="{{ route('commerce.billing.checkout') }}">
                    @csrf
                    <input type="hidden" name="plan" value="{{ $key }}">
                    <h3 style="margin:0;">{{ $plan['name'] }}</h3>
                    <div style="font-size:1.4rem;font-weight:700;">{{ \App\Commerce\Billing\Plans::priceLabel($key) }}</div>
                    <ul style="margin:0;padding-left:20px;line-height:1.8;">
                        <li>{{ $plan['limits']['users'] === null ? 'Equipe sem limite' : 'Até '.$plan['limits']['users'].' pessoas na equipe' }}</li>
                        <li>{{ $plan['limits']['products'] === null ? 'Produtos sem limite' : 'Até '.number_format($plan['limits']['products'], 0, ',', '.').' produtos' }}</li>
                        <li>{{ $plan['limits']['channels'] === null ? 'Todos os marketplaces' : ($plan['limits']['channels'] === 1 ? '1 marketplace conectado' : 'Até '.$plan['limits']['channels'].' marketplaces conectados') }}</li>
                    </ul>
                    <button type="submit">Assinar o {{ $plan['name'] }}</button>
                </form>
            @endforeach
        </div>
        <p class="helper" style="margin-top:12px;">O pagamento é feito na página segura do Stripe. A Foxy não vê nem guarda os dados do cartão.</p>
    @endunless
</x-core::layout>
