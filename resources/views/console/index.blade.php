@extends('console.layout')

@section('body')
<header>
    <div><strong>Foxy AI</strong> — Console de testes <span class="badge">provedor: {{ $provider }}</span></div>
    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Sair</button></form>
</header>

<main>
    @unless ($ready)
        <div class="card error">A demonstração ainda não foi configurada. Rode <code>php artisan foxy:demo:setup</code> no servidor.</div>
    @endunless

    <div class="card">
        <p class="muted" style="margin-top:0">Você está usando a <strong>Clínica Bem Viver</strong>, um negócio fictício. A IA conversa pela mesma API que um sistema real usaria; as consultas rodam sobre dados inventados do mês atual.</p>

        <div class="row">
            <div>
                <label for="model">Modelo</label>
                <select id="model">
                    @foreach ($models as $model)
                        <option value="{{ $model }}" @selected($model === $defaultModel)>{{ $model }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="role">Perfil de acesso do usuário</label>
                <select id="role">
                    @foreach ($roles as $role)
                        <option value="{{ $role }}">{{ $role === 'dono' ? 'Dono (vê tudo)' : 'Recepção (sem financeiro)' }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="page">Tela atual (opcional)</label>
                <input id="page" placeholder="Ex.: Agenda › Novo atendimento">
            </div>
        </div>
        <div class="row">
            <div>
                <label for="error_message">Erro mostrado na tela (opcional)</label>
                <input id="error_message" placeholder="Ex.: Horário indisponível">
            </div>
            <div>
                <label for="error_code">Código do erro (opcional)</label>
                <input id="error_code" placeholder="Ex.: AG-409">
            </div>
        </div>

        <label for="question">Pergunta</label>
        <textarea id="question" placeholder="Ex.: Quem mais me deve este mês?"></textarea>

        <div class="chips" style="margin-top:8px">
            <span class="muted">Perguntas de teste:</span>
            @foreach ($questions as $q)
                <button type="button" data-question="{{ $q }}">{{ $q }}</button>
            @endforeach
        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:8px">
            <button class="primary" id="send" type="button">Enviar</button>
            <button id="reset" type="button">Nova conversa</button>
            <span class="muted" style="margin-left:16px">Comparar em:</span>
            @foreach ($models as $model)
                <label style="display:inline; margin:0"><input type="checkbox" class="cmp" value="{{ $model }}" checked style="width:auto"> {{ $model }}</label>
            @endforeach
            <button id="compare" type="button">Comparar modelos</button>
        </div>
        <p class="muted" id="status"></p>
    </div>

    <div class="card" id="compare-card" hidden>
        <h3 style="margin-top:0">Comparação</h3>
        <div class="grid3" id="compare-results"></div>
    </div>

    <div class="card">
        <h3 style="margin-top:0">Conversa <span class="muted" id="totals"></span></h3>
        <div id="chat"><p class="muted">Nenhuma mensagem ainda.</p></div>
    </div>
</main>

<script>
(() => {
    const $ = (id) => document.getElementById(id);
    const token = document.querySelector('meta[name=csrf-token]').content;
    let total = { cost: 0, messages: 0 };

    const payload = () => ({
        question: $('question').value.trim(),
        role: $('role').value,
        model: $('model').value,
        page: $('page').value.trim() || null,
        error_message: $('error_message').value.trim() || null,
        error_code: $('error_code').value.trim() || null,
    });

    async function post(url, body) {
        const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify(body) });
        const json = await res.json().catch(() => ({}));
        if (!res.ok && !json.error) json.error = { message: json.message || ('Erro HTTP ' + res.status) };
        return json;
    }

    const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; };
    const money = (v) => 'US$ ' + Number(v || 0).toFixed(4);

    function renderAnswer(container, data) {
        if (data.error) {
            container.append(el('div', 'msg ai error', (data.error.code ? '[' + data.error.code + '] ' : '') + data.error.message));
            return;
        }
        container.append(el('div', 'msg ai', data.reply));
        if (data.escalate) container.append(el('div', 'msg ai', '→ Encaminhado ao suporte (' + data.escalate.reason + '): ' + data.escalate.summary));
        const u = data.usage || {};
        container.append(el('div', 'muted', (data.model || '') + ' · entrada ' + (u.input_tokens || 0) + ' · cache ' + (u.cache_read_tokens || 0) + ' · saída ' + (u.output_tokens || 0) + ' tokens · ' + money(u.cost_usd)));
        if (data.trace && data.trace.length) {
            const d = el('details'); d.append(el('summary', null, 'Consultas executadas pelo sistema (' + data.trace.length + ')'));
            data.trace.forEach(t => d.append(el('pre', null, t.tool + ' ' + JSON.stringify(t.input) + '\n→ ' + JSON.stringify(t.output, null, 2))));
            container.append(d);
        }
    }

    function busy(on, text) {
        ['send', 'compare', 'reset'].forEach(id => $(id).disabled = on);
        $('status').textContent = text || '';
    }

    document.querySelectorAll('[data-question]').forEach(b => b.addEventListener('click', () => { $('question').value = b.dataset.question; $('question').focus(); }));

    $('send').addEventListener('click', async () => {
        const body = payload();
        if (!body.question) return;
        busy(true, 'Pensando…');
        const chat = $('chat');
        if (!total.messages) chat.innerHTML = '';
        const data = await post('/console/chat', body);
        if (data.new_conversation && total.messages) chat.append(el('p', 'muted', '— nova conversa (' + body.model + ', ' + body.role + ') —'));
        chat.append(el('div', 'msg user', body.question));
        renderAnswer(chat, data);
        total.messages++; total.cost += Number(data.usage?.cost_usd || 0);
        $('totals').textContent = '— ' + total.messages + ' mensagens · ' + money(total.cost);
        $('question').value = '';
        busy(false);
    });

    $('reset').addEventListener('click', async () => {
        await post('/console/reset', {});
        $('chat').innerHTML = '<p class="muted">Nova conversa.</p>';
        total = { cost: 0, messages: 0 }; $('totals').textContent = '';
    });

    $('compare').addEventListener('click', async () => {
        const body = payload();
        body.models = [...document.querySelectorAll('.cmp:checked')].map(c => c.value);
        if (!body.question || !body.models.length) return;
        busy(true, 'Comparando em ' + body.models.length + ' modelos…');
        const data = await post('/console/compare', body);
        const box = $('compare-results'); box.innerHTML = ''; $('compare-card').hidden = false;
        if (data.error) box.append(el('div', 'error', data.error.message));
        (data.results || []).forEach(r => {
            const col = el('div', 'card'); col.append(el('strong', null, r.model)); renderAnswer(col, r); box.append(col);
        });
        busy(false);
    });
})();
</script>
@endsection
