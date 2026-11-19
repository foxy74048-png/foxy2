<x-core::layout :title="$contact->name" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    @php($money = fn ($c) => \App\Platform\Support\Money::format($c))

    @include('finance.status')

    <p style="margin:0 0 12px;"><a href="{{ route('finance.debts') }}">← Quem me deve</a></p>

    <div class="grid cards">
        <div class="card">
            <div class="muted">{{ $balance > 0 ? 'Te deve' : ($balance < 0 ? 'Você deve' : 'Saldo') }}</div>
            <h2 style="margin:4px 0;{{ $balance > 0 ? 'color:var(--success-text);' : ($balance < 0 ? 'color:var(--danger-text);' : '') }}">{{ $balance === 0 ? 'Quites' : $money(abs($balance)) }}</h2>
            @if ($reminder)
                <a class="button" href="{{ $reminder }}" target="_blank" rel="noopener">Lembrar pelo WhatsApp</a>
                <div class="helper" style="margin-top:6px;">Abre o seu WhatsApp com a mensagem pronta; você confere e envia. A Foxy não manda nada sozinha.</div>
            @elseif ($balance > 0)
                <div class="helper">Coloque o WhatsApp da pessoa abaixo para mandar um lembrete.</div>
            @endif
        </div>
        @if ($canManage)
            <form class="card stack" method="POST" action="{{ route('finance.debts.contact.update', $contact) }}">
                @csrf
                <div><label for="c-name">Nome</label><input id="c-name" name="name" value="{{ $contact->name }}" maxlength="80" required></div>
                <div><label for="c-phone">WhatsApp (opcional)</label><input id="c-phone" name="phone" value="{{ $contact->phone }}" maxlength="30" placeholder="(11) 99999-0000"></div>
                <div><button type="submit" class="secondary">Salvar</button></div>
            </form>
        @endif
    </div>

    @if ($canManage && $balance !== 0)
        <form class="card form-grid" method="POST" action="{{ route('finance.debts.store') }}" style="margin-top:16px;align-items:end;">
            @csrf
            <input type="hidden" name="contact_id" value="{{ $contact->id }}">
            <input type="hidden" name="kind" value="{{ $balance > 0 ? 'devolveu' : 'paguei' }}">
            <div style="grid-column:1/-1;"><h3 style="margin:0;">{{ $balance > 0 ? $contact->name.' devolveu' : 'Paguei '.$contact->name }}</h3><p class="helper" style="margin:4px 0 0;">Pode ser só uma parte.</p></div>
            <div><label for="r-amount">Valor (R$)</label><input id="r-amount" name="amount" value="{{ \App\Platform\Support\Money::input(abs($balance)) }}" inputmode="decimal" required></div>
            <div><label for="r-date">Data</label><input id="r-date" name="date" type="date" value="{{ today()->toDateString() }}" required></div>
            <div><label for="r-account">{{ $balance > 0 ? 'Entrou em' : 'Saiu de' }} (opcional)</label><select id="r-account" name="account_id"><option value="">— só anotar —</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
            <div><button type="submit">Registrar</button></div>
        </form>
    @endif

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Histórico</h3>
        @if ($entries->isEmpty())
            <div class="empty">Nada registrado com {{ $contact->name }}.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Data</th><th>O quê</th><th class="num">Valor</th>@if ($canManage)<th></th>@endif</tr></thead>
                <tbody>
                    @foreach ($entries as $e)
                        <tr>
                            <td style="white-space:nowrap;">{{ $e->date->format('d/m/Y') }}</td>
                            <td>
                                <strong>{{ $e->label() }}</strong>@if ($e->description) · {{ $e->description }}@endif
                                @if ($e->kind === 'objeto')<div class="helper">{{ $e->returned_at ? 'devolvido em '.$e->returned_at->format('d/m/Y') : 'ainda com a pessoa' }}</div>@endif
                                @if ($e->due_date)<div class="helper">combinado para {{ $e->due_date->format('d/m/Y') }}</div>@endif
                                @if ($e->transaction_id)<div class="helper">registrado também nos lançamentos</div>@endif
                            </td>
                            <td class="num" style="{{ $e->amount_cents > 0 ? 'color:var(--success-text);' : '' }}">{{ $e->amount_cents === 0 ? '—' : ($e->amount_cents > 0 ? '+ ' : '− ').$money(abs($e->amount_cents)) }}</td>
                            @if ($canManage)
                                <td style="text-align:right;white-space:nowrap;">
                                    @if ($e->kind === 'objeto')<form method="POST" action="{{ route('finance.debts.return', $e) }}" style="display:inline;">@csrf @if ($e->returned_at)<input type="hidden" name="undo" value="1">@endif<button type="submit" class="secondary">{{ $e->returned_at ? 'Ainda está com a pessoa' : 'Já devolveu' }}</button></form>@endif
                                    <form method="POST" action="{{ route('finance.debts.entry.destroy', $e) }}" style="display:inline;" onsubmit="return confirm('{{ $e->group_id ? 'Apagar a divisão inteira (todas as pessoas e os lançamentos dela)?' : 'Apagar este movimento?' }}');">@csrf<button type="submit" class="danger">Apagar</button></form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <p class="helper" style="margin-bottom:0;">+ aumenta o que {{ $contact->name }} te deve; − diminui (ou é o que você deve).</p>
        @endif
    </div>

    @if ($canManage && $balance === 0)
        <form method="POST" action="{{ route('finance.debts.contact.archive', $contact) }}" style="margin-top:12px;">@csrf<input type="hidden" name="archive" value="{{ $contact->archived_at ? 0 : 1 }}"><button type="submit" class="secondary">{{ $contact->archived_at ? 'Voltar para a lista' : 'Tirar da lista (estão quites)' }}</button></form>
    @endif
</x-core::layout>
