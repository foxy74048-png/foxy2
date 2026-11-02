<x-core::layout title="Console de testes" product="Foxy AI" subtitle="Equipe Foxy">
    <x-slot:head>
        <link rel="stylesheet" href="/core/foxy-assistant.css">
        <style>
            .foxes { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
            .fox { display: flex; flex-direction: column; align-items: center; gap: 4px; text-align: center; padding: 14px;
                   border: 1px solid var(--border); border-radius: 16px; cursor: pointer; margin: 0; font-weight: 400; background: #fff; }
            .fox:has(input:checked) { border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary) inset; }
            .fox img { width: 64px; height: 64px; }
            .fox em { font-size: 0.85rem; }
            .compare-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
            pre { background: var(--bg); padding: 8px; border-radius: 10px; font-size: 12px; white-space: pre-wrap; overflow-wrap: anywhere; margin: 6px 0; }
            .answer-text { white-space: pre-wrap; }
        </style>
    </x-slot:head>

    <x-slot:nav>
        <x-core::nav-link href="/console" :active="true" icon="sparkles">Console de testes</x-core::nav-link>
    </x-slot:nav>

    <x-slot:actions>
        <span class="badge">provedor: {{ $provider }}</span>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="secondary"><x-core::icon name="logout" /> Sair</button></form>
    </x-slot:actions>

    @unless ($ready)
        <div class="alert error">A demonstração ainda não foi configurada. Rode <code>php artisan foxy:demo:setup</code> no servidor.</div>
    @endunless

    <div class="alert info">
        Você está na <strong>Clínica Bem Viver (demonstração)</strong>, com dados inventados do mês atual. A janela da raposa
        (canto inferior direito) é a mesma que vai aparecer nos sistemas Foxy e conversa pela API como um sistema real.
    </div>

    <section class="card stack" aria-labelledby="fox-title">
        <h2 id="fox-title">Escolha a sua raposa</h2>
        <div class="foxes" role="radiogroup" aria-label="Raposa">
            @foreach ($foxes as $fox)
                <label class="fox">
                    <input type="radio" name="assistant" value="{{ $fox['id'] }}" @checked($fox['id'] === $current['id'])>
                    <img src="{{ $fox['avatars'][$current['visual']] }}" alt="" data-avatars='@json($fox['avatars'])'>
                    <strong>{{ $fox['name'] }}</strong>
                    <span class="muted">{{ $fox['style'] }}</span>
                    <em class="muted">“{{ $fox['sample'] }}”</em>
                </label>
            @endforeach
        </div>

        <h3 style="margin:8px 0 0">Estilo do desenho</h3>
        <div class="foxes" role="radiogroup" aria-label="Estilo do desenho">
            @foreach ($visuals as $visual => $label)
                <label class="fox">
                    <input type="radio" name="visual" value="{{ $visual }}" @checked($visual === $current['visual'])>
                    <img src="{{ \App\Ai\Assistants\FoxCatalog::avatarUrl($current['id'], $visual) }}" alt="" data-visual="{{ $visual }}">
                    <strong>{{ \Illuminate\Support\Str::before($label, ' —') }}</strong>
                    <span class="muted">{{ \Illuminate\Support\Str::after($label, '— ') }}</span>
                </label>
            @endforeach
        </div>
        <div class="form-grid">
            <div>
                <label for="custom_name">Dar outro nome (opcional)</label>
                <input id="custom_name" maxlength="40" value="{{ ($current['custom'] ?? false) ? $current['name'] : '' }}" placeholder="Ex.: Lulu da Recepção">
            </div>
            <div style="align-self:end"><button type="button" id="save-fox">Usar esta raposa</button></div>
        </div>
        <p class="muted" id="fox-status" role="status"></p>
    </section>

    <section class="card stack" aria-labelledby="setup-title">
        <h2 id="setup-title">Situação do teste</h2>
        <div class="form-grid">
            <div>
                <label for="model">Modelo</label>
                <select id="model">
                    @foreach ($models as $model)
                        <option value="{{ $model }}" @selected($model === $defaultModel)>{{ $model }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="role">Perfil de acesso da pessoa</label>
                <select id="role">
                    @foreach ($roles as $role)
                        <option value="{{ $role }}">{{ $role === 'dono' ? 'Titular da conta (vê tudo)' : 'Recepção (sem financeiro)' }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="page">Tela em que a pessoa está</label>
                <input id="page" value="Agenda › Semana">
            </div>
        </div>
        <div class="form-grid">
            <div>
                <label for="error_message">Erro na tela (para simular)</label>
                <input id="error_message" placeholder="Ex.: Horário indisponível">
            </div>
            <div>
                <label for="error_code">Código do erro</label>
                <input id="error_code" placeholder="Ex.: AG-409">
            </div>
            <div style="align-self:end"><button type="button" class="secondary" id="simulate-error">Simular erro na tela</button></div>
        </div>
        <p class="helper">Trocar modelo ou perfil abre uma conversa nova na próxima pergunta.</p>
    </section>

    <section class="card stack" aria-labelledby="last-title">
        <h2 id="last-title">Última resposta (detalhes)</h2>
        <div id="last" class="muted">Faça uma pergunta na janela da raposa.</div>
    </section>

    <section class="card stack" aria-labelledby="compare-title">
        <h2 id="compare-title">Comparar modelos</h2>
        <div>
            <label for="compare_question">Pergunta</label>
            <input id="compare_question" value="{{ $questions[0] ?? '' }}">
        </div>
        <div class="toolbar" style="justify-content:flex-start">
            @foreach ($models as $model)
                <label style="display:inline-flex;gap:6px;align-items:center;margin:0"><input type="checkbox" class="cmp" value="{{ $model }}" checked> {{ $model }}</label>
            @endforeach
            <button type="button" id="compare">Comparar</button>
        </div>
        <div class="compare-grid" id="compare-results"></div>
    </section>

    <x-slot:scripts>
        <script src="/core/foxy-assistant.js"></script>
        <script>
        (() => {
            const $ = (id) => document.getElementById(id);
            const token = document.querySelector('meta[name=csrf-token]').content;
            const money = (v) => 'US$ ' + Number(v || 0).toFixed(4);
            const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; };
            const post = async (url, body) => {
                const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify(body) });
                const json = await res.json().catch(() => ({}));
                if (!res.ok && !json.error) json.error = { message: json.message || ('Erro ' + res.status) };
                return json;
            };

            const details = (box, data) => {
                box.innerHTML = '';
                if (data.error) { box.append(el('div', 'alert error', (data.error.code ? '[' + data.error.code + '] ' : '') + data.error.message)); return; }
                const u = data.usage || {};
                box.append(el('p', null, (data.model || '') + ' · entrada ' + (u.input_tokens || 0) + ' · cache ' + (u.cache_read_tokens || 0) + ' · saída ' + (u.output_tokens || 0) + ' tokens · ' + money(u.cost_usd)));
                (data.trace || []).forEach(t => box.append(el('pre', null, t.tool + ' ' + JSON.stringify(t.input) + '\n→ ' + JSON.stringify(t.output, null, 2))));
                if (!(data.trace || []).length) box.append(el('p', 'muted', 'Nenhuma consulta ao sistema nesta resposta.'));
            };

            const assistant = FoxyAssistant.mount({
                endpoint: '/console/assistant/messages',
                resetEndpoint: '/console/assistant/reset',
                csrfToken: token,
                assistant: @json(['name' => $current['name'], 'article' => $current['article'], 'avatar_url' => $current['avatar_url']]),
                suggestions: @json($questions),
                getPage: () => $('page').value.trim(),
                extra: () => ({ model: $('model').value, role: $('role').value }),
                draftActions: [{ label: 'Abrir no WhatsApp', channels: ['whatsapp'], onClick: (d) => window.open('https://wa.me/?text=' + encodeURIComponent(d.text), '_blank', 'noopener') }],
                onAnswer: (data) => details($('last'), data),
            });

            // As imagens acompanham a escolha: raposas no estilo marcado, estilos com a raposa marcada.
            const chosenFox = () => document.querySelector('input[name=assistant]:checked');
            const chosenVisual = () => document.querySelector('input[name=visual]:checked').value;
            const refreshImages = () => {
                const visual = chosenVisual();
                document.querySelectorAll('img[data-avatars]').forEach(img => { img.src = JSON.parse(img.dataset.avatars)[visual]; });
                const avatars = JSON.parse(chosenFox().closest('label').querySelector('img').dataset.avatars);
                document.querySelectorAll('img[data-visual]').forEach(img => { img.src = avatars[img.dataset.visual]; });
            };
            document.querySelectorAll('input[name=assistant], input[name=visual]').forEach(i => i.addEventListener('change', refreshImages));

            $('save-fox').addEventListener('click', async () => {
                const chosen = chosenFox().value;
                const data = await post('/console/assistant/fox', { assistant: chosen, visual: chosenVisual(), custom_name: $('custom_name').value.trim() || null });
                if (data.error) { $('fox-status').textContent = data.error.message; return; }
                assistant.setAssistant(data.assistant);
                $('fox-status').textContent = 'Pronto: agora quem atende é ' + data.assistant.name + '. A próxima conversa já começa com essa raposa.';
            });

            $('simulate-error').addEventListener('click', () => {
                const message = $('error_message').value.trim();
                if (!message) { $('error_message').focus(); return; }
                assistant.reportError({ message, code: $('error_code').value.trim() || undefined });
            });

            $('compare').addEventListener('click', async () => {
                const models = [...document.querySelectorAll('.cmp:checked')].map(c => c.value);
                const question = $('compare_question').value.trim();
                if (!question || !models.length) return;
                const box = $('compare-results');
                box.innerHTML = '<p class="muted">Comparando…</p>';
                $('compare').disabled = true;
                const data = await post('/console/compare', { question, models, role: $('role').value, page: $('page').value.trim() || null });
                box.innerHTML = '';
                if (data.error) box.append(el('div', 'alert error', data.error.message));
                (data.results || []).forEach(r => {
                    const col = el('div', 'card stack');
                    col.append(el('strong', null, r.model));
                    if (r.reply) col.append(el('div', 'answer-text', r.reply));
                    (r.drafts || []).forEach(d => col.append(el('pre', null, '[' + d.channel + '] ' + d.text)));
                    const info = el('div'); details(info, r); col.append(info);
                    box.append(col);
                });
                $('compare').disabled = false;
            });
        })();
        </script>
    </x-slot:scripts>
</x-core::layout>
