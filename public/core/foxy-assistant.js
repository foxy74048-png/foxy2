/*
 * Janela da raposa (Foxy AI) — sem dependências. O navegador fala só com o servidor do próprio sistema
 * (options.endpoint); é ele que guarda a chave da Foxy AI e executa as consultas com as permissões da pessoa.
 *
 * Contrato do endpoint do sistema (ver docs/06-api-foxy-ai.md, seção "Janela da raposa"):
 *   POST endpoint       { text, page, error, mode, ...extra() }  →  { reply, drafts, escalate, assistant, tools_used, error, usage_text }
 *   POST resetEndpoint  { mode, ...extra() }                     →  { assistant, usage_text }   (opcional: começa conversa nova)
 *   POST setup.endpoint { assistant, visual, custom_name }       →  { assistant, usage_text }   (opcional: escolha da raposa no chat)
 *   GET  settingsEndpoint                                     →  { usage: { title, percent, lines: [..] }, links: [{ label, url }] }   (opcional: tela ⚙)
 *   GET  historyEndpoint                                      →  { messages: [{ role: 'user'|'ai', text }], assistant, usage_text }   (opcional)
 *   error pode trazer link: { label, url } (botão no balão de erro, ex.: comprar mais perguntas).
 *   Qualquer resposta pode trazer usage_text (mostrado no rodapé) e setup_required: true (abre a escolha da raposa).
 *
 * Uso:
 *   FoxyAssistant.mount({
 *     endpoint: '/assistente/mensagens', resetEndpoint: '/assistente/nova',
 *     csrfToken: '...', assistant: { name: 'Lud', avatarUrl: '/core/assistants/lud.svg' },
 *     suggestions: ['Como remarco um atendimento?'], getPage: () => document.title,
 *     draftActions: [{ label: 'Abrir no WhatsApp', onClick: (draft) => ... }],
 *     onAnswer: (data) => {}, extra: () => ({}),
 *     launcher: 'icon',                                   // botão redondo só com a raposa (padrão 'pill', com texto)
 *     modes: [{ id: 'rapida', label: 'Rápido', hint: '≈ 1 crédito por pergunta' }], mode: 'rapida',
 *     setup: { endpoint: '/assistente/raposa', required: true, foxes: [...], visuals: { facetas: '...' } },
 *     escalateAction: { label: 'Falar com o suporte', onClick: (escalate) => ... },
 *     usageText: 'Restam ~240 perguntas neste mês',
 *   });
 */
(function (global) {
    'use strict';

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    /**
     * Resposta da IA com **negrito**: vira <strong> sem usar innerHTML (o texto da IA nunca vira HTML).
     */
    function aiMessage(text) {
        const node = el('div', 'fxa-msg ai');
        String(text || '').split(/(\*\*[^*\n]+\*\*)/g).forEach((part) => {
            if (/^\*\*[^*\n]+\*\*$/.test(part)) node.append(el('strong', null, part.slice(2, -2)));
            else if (part) node.append(document.createTextNode(part));
        });
        return node;
    }

    /** Aceita o formato da API (avatar_url, custom_name) e o do JS (avatarUrl, customName). */
    function normalize(a) {
        a = Object.assign({}, a || {});
        if (a.avatar_url && !a.avatarUrl) a.avatarUrl = a.avatar_url;
        if (a.custom_name !== undefined && a.customName === undefined) a.customName = a.custom_name;
        return a;
    }

    function mount(options) {
        const opts = Object.assign({
            suggestions: [],
            getPage: () => document.title,
            draftActions: [],
            extra: () => ({}),
            onAnswer: () => {},
            open: false,
            launcher: 'pill',
            modes: [],
            mode: null,
            setup: null,
            escalateAction: null,
            usageText: '',
            historyEndpoint: null,
            settingsEndpoint: null,
        }, options || {});

        let assistant = Object.assign({ name: 'Lud', article: 'a', avatarUrl: '/core/assistants/lud.svg' }, normalize(opts.assistant));
        let busy = false;
        let pendingError = null;
        let mode = opts.mode || (opts.modes[0] && opts.modes[0].id) || null;
        let needsSetup = !!(opts.setup && opts.setup.required);
        const uid = Math.random().toString(36).slice(2, 8);

        // ----- Estrutura -----
        const launcher = el('button', 'fxa-launcher' + (opts.launcher === 'icon' ? ' fxa-launcher-icon' : ''));
        launcher.type = 'button';
        const launcherImg = el('img'); launcherImg.alt = '';
        const launcherText = el('span');
        launcher.append(launcherImg, launcherText);

        const panel = el('section', 'fxa-panel');
        panel.hidden = true;
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'false');

        const head = el('div', 'fxa-head');
        const headImg = el('img'); headImg.alt = '';
        const who = el('div', 'fxa-who');
        const headName = el('strong');
        who.append(headName, el('small', null, 'Assistente da Foxy'));
        // ⚙ Configurações: a raposa, o modo e o uso do mês, dentro da própria janela.
        const gearBtn = el('button', 'fxa-icon-btn fxa-gear', '⚙'); gearBtn.type = 'button';
        gearBtn.title = 'Configurações'; gearBtn.setAttribute('aria-label', 'Configurações');
        gearBtn.hidden = !(opts.setup || opts.settingsEndpoint || (opts.modes && opts.modes.length > 1));
        const newBtn = el('button', 'fxa-icon-btn', 'Nova conversa'); newBtn.type = 'button';
        const closeBtn = el('button', 'fxa-icon-btn', '✕'); closeBtn.type = 'button'; closeBtn.setAttribute('aria-label', 'Fechar');
        head.append(headImg, who, gearBtn, newBtn, closeBtn);

        const body = el('div', 'fxa-body');
        body.setAttribute('aria-live', 'polite');
        const settingsView = el('div', 'fxa-body fxa-settings');
        settingsView.hidden = true;

        const foot = el('div', 'fxa-foot');

        // Modo (faixa de modelo): sempre à mostra, com o gasto de cada um.
        const modeRow = el('div', 'fxa-mode');
        const modeLabel = el('label', null, 'Modo');
        const modeSelect = el('select');
        modeSelect.id = 'fxa-mode-' + uid;
        modeLabel.htmlFor = modeSelect.id;
        const modeHint = el('span', 'fxa-mode-hint');
        (opts.modes || []).forEach((m) => { const o = el('option', null, m.label); o.value = m.id; modeSelect.append(o); });
        modeRow.append(modeLabel, modeSelect, modeHint);
        modeRow.hidden = !(opts.modes && opts.modes.length > 1);

        const form = el('form', 'fxa-form');
        const input = el('textarea');
        input.rows = 1;
        input.setAttribute('aria-label', 'Sua mensagem');
        const send = el('button', null, 'Enviar'); send.type = 'submit';
        form.append(input, send);

        const usageLine = el('p', 'fxa-usage');
        foot.append(modeRow, form, usageLine, el('p', 'fxa-disclaimer', 'A IA pode errar. Confira antes de usar ou enviar.'));

        panel.append(head, body, settingsView, foot);
        document.body.append(launcher, panel);

        function applyAssistant() {
            launcherImg.src = headImg.src = assistant.avatarUrl;
            launcherText.textContent = 'Pergunte ' + (assistant.article === 'o' ? 'ao ' : 'à ') + assistant.name;
            launcher.title = launcherText.textContent;
            launcher.setAttribute('aria-label', launcherText.textContent);
            headName.textContent = assistant.name;
            panel.setAttribute('aria-label', 'Conversa com ' + assistant.name);
            input.placeholder = 'Pergunte ' + (assistant.article === 'o' ? 'ao ' : 'à ') + assistant.name + '…';
        }

        function applyMode() {
            const current = (opts.modes || []).find((m) => m.id === mode);
            if (current) modeSelect.value = current.id;
            modeHint.textContent = current && current.hint ? current.hint : '';
        }

        function applyUsage(text) {
            if (typeof text === 'string') opts.usageText = text;
            usageLine.textContent = opts.usageText || '';
            usageLine.hidden = !opts.usageText;
        }

        function suggestions() {
            const list = typeof opts.suggestions === 'function' ? opts.suggestions(opts.getPage()) : opts.suggestions;
            return (list || []).slice(0, 6);
        }

        function greet() {
            body.innerHTML = '';
            body.append(el('div', 'fxa-hello', 'Oi! Eu sou ' + (assistant.article === 'o' ? 'o ' : 'a ') + assistant.name
                + '. Posso explicar como usar o sistema, ajudar com erros, consultar seus dados e sugerir mensagens.'));
            const items = suggestions();
            if (items.length) {
                body.append(el('div', 'fxa-note', 'Sugestões para esta tela:'));
                const chips = el('div', 'fxa-chips');
                items.forEach((text) => {
                    const chip = el('button', null, text); chip.type = 'button';
                    chip.addEventListener('click', () => ask(text));
                    chips.append(chip);
                });
                body.append(chips);
            }
        }

        /** Escolha da raposa dentro do chat: a própria raposa conduz a configuração. */
        function chooser(first) {
            body.innerHTML = '';
            const s = opts.setup || {};
            body.append(el('div', 'fxa-hello', first
                ? 'Oi! Antes de começar, escolha quem vai te acompanhar. Dá para trocar depois, quando quiser.'
                : 'Escolha a sua raposa. Vale a partir da próxima conversa.'));

            let picked = assistant.id || (s.foxes && s.foxes[0] && s.foxes[0].id);
            let visual = assistant.visual || Object.keys(s.visuals || {})[0] || null;

            const grid = el('div', 'fxa-foxes');
            const cards = [];
            (s.foxes || []).forEach((fox) => {
                const card = el('button', 'fxa-fox'); card.type = 'button';
                const img = el('img'); img.alt = '';
                card.append(img, el('strong', null, fox.name), el('span', null, fox.style || ''));
                if (fox.sample) card.append(el('em', null, '“' + fox.sample + '”'));
                card.addEventListener('click', () => { picked = fox.id; paint(); });
                cards.push({ fox: fox, card: card, img: img });
                grid.append(card);
            });
            body.append(grid);

            const visuals = Object.keys(s.visuals || {});
            const styleRow = el('div', 'fxa-chips fxa-visuals');
            const styleButtons = visuals.map((v) => {
                const b = el('button', null, String(s.visuals[v] || v).split(' — ')[0]); b.type = 'button';
                b.title = s.visuals[v] || v;
                b.addEventListener('click', () => { visual = v; paint(); });
                styleRow.append(b);
                return { v: v, b: b };
            });
            if (visuals.length > 1) body.append(el('div', 'fxa-note', 'Estilo do desenho:'), styleRow);

            const nameLabel = el('label', 'fxa-note', 'Quer dar outro nome? (opcional)');
            const nameInput = el('input', 'fxa-name');
            nameInput.maxLength = 40; nameInput.id = 'fxa-name-' + uid; nameLabel.htmlFor = nameInput.id;
            nameInput.value = assistant.customName || '';
            const confirm = el('button', 'fxa-primary'); confirm.type = 'button';
            const status = el('div', 'fxa-note');
            body.append(nameLabel, nameInput, confirm, status);

            function paint() {
                cards.forEach(({ fox, card, img }) => {
                    img.src = (fox.avatars && visual && fox.avatars[visual]) || fox.avatar_url;
                    card.classList.toggle('selected', fox.id === picked);
                    card.setAttribute('aria-pressed', fox.id === picked ? 'true' : 'false');
                });
                styleButtons.forEach(({ v, b }) => b.classList.toggle('selected', v === visual));
                const chosen = cards.find((c) => c.fox.id === picked);
                confirm.textContent = 'Começar com ' + ((nameInput.value || '').trim() || (chosen ? chosen.fox.name : 'a raposa'));
            }
            nameInput.addEventListener('input', paint);

            confirm.addEventListener('click', async () => {
                confirm.disabled = true; status.textContent = 'Salvando…';
                let data;
                try { data = await post(s.endpoint, { assistant: picked, visual: visual, custom_name: (nameInput.value || '').trim() || null }); }
                catch (e) { data = { error: { message: 'Sem conexão. Tente de novo.' } }; }
                confirm.disabled = false;
                if (data.error) { status.textContent = data.error.message || 'Não foi possível salvar.'; return; }
                needsSetup = false;
                if (data.assistant) assistant = Object.assign(assistant, normalize(data.assistant));
                applyAssistant(); applyUsage(data.usage_text); greet();
            });
            paint(); scroll();
        }

        function closeSettings() {
            settingsView.hidden = true; body.hidden = false; foot.hidden = false;
            gearBtn.setAttribute('aria-pressed', 'false');
        }

        /** Tela ⚙: a raposa (trocar), o modo e os indicadores de uso do mês. A conversa fica guardada por trás. */
        async function openSettings() {
            if (!settingsView.hidden) { closeSettings(); return; }
            body.hidden = true; foot.hidden = true; settingsView.hidden = false;
            gearBtn.setAttribute('aria-pressed', 'true');
            settingsView.innerHTML = '';

            const back = el('button', 'fxa-link-btn', '← Voltar à conversa'); back.type = 'button';
            back.addEventListener('click', closeSettings);
            settingsView.append(back, el('h3', 'fxa-settings-title', 'Configurações'));

            // Sua raposa
            if (opts.setup) {
                const card = el('div', 'fxa-settings-card');
                const row = el('div', 'fxa-settings-fox');
                const img = el('img'); img.alt = ''; img.src = assistant.avatarUrl;
                const text = el('div');
                text.append(el('strong', null, assistant.name), el('span', null, 'Sua raposa'));
                row.append(img, text);
                const change = el('button', 'fxa-primary', 'Trocar raposa, estilo ou nome'); change.type = 'button';
                change.addEventListener('click', () => { closeSettings(); chooser(false); });
                card.append(row, change);
                settingsView.append(card);
            }

            // Modo
            if (opts.modes && opts.modes.length > 1) {
                const card = el('div', 'fxa-settings-card');
                card.append(el('strong', null, 'Modo das respostas'));
                (opts.modes || []).forEach((m) => {
                    const label = el('label', 'fxa-radio');
                    const radio = el('input'); radio.type = 'radio'; radio.name = 'fxa-mode-radio-' + uid; radio.value = m.id; radio.checked = m.id === mode;
                    const textBox = el('span');
                    textBox.append(el('b', null, m.label), el('small', null, m.hint || ''));
                    label.append(radio, textBox);
                    radio.addEventListener('change', () => { mode = m.id; applyMode(); closeSettings(); reset(); });
                    card.append(label);
                });
                card.append(el('small', 'fxa-muted', 'Trocar o modo começa uma conversa nova.'));
                settingsView.append(card);
            }

            // Uso do mês
            if (opts.settingsEndpoint) {
                const card = el('div', 'fxa-settings-card');
                card.append(el('strong', null, 'Uso deste mês'));
                const loading = el('div', 'fxa-thinking', 'Carregando');
                card.append(loading);
                settingsView.append(card);

                let data = null;
                try {
                    const response = await fetch(opts.settingsEndpoint, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                    data = response.ok ? await response.json() : null;
                } catch (e) { data = null; }
                loading.remove();

                const usage = data && data.usage;
                if (!usage) {
                    card.append(el('small', 'fxa-muted', (data && data.error && data.error.message) || 'Não foi possível carregar agora.'));
                } else {
                    if (usage.title) card.append(el('div', 'fxa-usage-title', usage.title));
                    if (typeof usage.percent === 'number') {
                        const bar = el('div', 'fxa-bar');
                        const fill = el('span');
                        fill.style.width = Math.min(100, Math.max(0, usage.percent)) + '%';
                        if (usage.percent >= 90) fill.className = 'danger';
                        bar.setAttribute('role', 'progressbar');
                        bar.setAttribute('aria-valuenow', String(usage.percent));
                        bar.setAttribute('aria-valuemin', '0'); bar.setAttribute('aria-valuemax', '100');
                        bar.append(fill);
                        card.append(bar);
                    }
                    const list = el('ul', 'fxa-usage-lines');
                    (usage.lines || []).forEach((line) => list.append(el('li', null, line)));
                    card.append(list);
                }
                (data && data.links || []).forEach((link) => {
                    const a = el('a', 'fxa-error-link', link.label); a.href = link.url;
                    card.append(a);
                });
            }
        }

        function scroll() { body.scrollTop = body.scrollHeight; }

        function draftCard(draft) {
            const card = el('div', 'fxa-card');
            const label = { whatsapp: 'WhatsApp', email: 'E-mail', texto: 'Texto' }[draft.channel] || draft.channel;
            card.append(el('div', 'fxa-card-title', 'Mensagem sugerida · ' + label + (draft.title ? ' · ' + draft.title : '')));
            card.append(el('div', 'fxa-card-text', draft.text));
            const actions = el('div', 'fxa-actions');
            const copy = el('button', null, 'Copiar'); copy.type = 'button';
            copy.addEventListener('click', async () => {
                try { await navigator.clipboard.writeText(draft.text); copy.textContent = 'Copiado'; }
                catch (e) { copy.textContent = 'Selecione e copie'; }
            });
            actions.append(copy);
            (opts.draftActions || []).forEach((action) => {
                if (action.channels && !action.channels.includes(draft.channel)) return;
                const b = el('button', 'secondary', action.label); b.type = 'button';
                b.addEventListener('click', () => action.onClick(draft));
                actions.append(b);
            });
            card.append(actions);
            return card;
        }

        function render(data) {
            if (data.error) {
                const box = el('div', 'fxa-msg error', data.error.message || 'Não foi possível responder agora.');
                // Ex.: perguntas do mês acabaram → { label: 'Comprar mais perguntas', url: '/assistente/comprar' }
                if (data.error.link && data.error.link.url) {
                    const a = el('a', 'fxa-error-link', data.error.link.label || 'Abrir');
                    a.href = data.error.link.url;
                    box.append(el('br'), a);
                }
                body.append(box);
                return;
            }
            if (data.tools_used && data.tools_used.length) {
                body.append(el('div', 'fxa-note', '🔎 Consultou: ' + data.tools_used.join(', ')));
            }
            if (data.reply) body.append(aiMessage(data.reply));
            (data.drafts || []).forEach((d) => body.append(draftCard(d)));
            if (data.feature_request) body.append(el('div', 'fxa-note', '📝 Ideia anotada para a equipe avaliar.'));
            if (data.escalate) {
                const card = el('div', 'fxa-card escalate');
                card.append(el('div', 'fxa-card-title', 'Encaminhado ao suporte'), el('div', 'fxa-card-text', data.escalate.summary));
                if (opts.escalateAction) {
                    const actions = el('div', 'fxa-actions');
                    const b = el('button', null, opts.escalateAction.label); b.type = 'button';
                    b.addEventListener('click', () => opts.escalateAction.onClick(data.escalate));
                    actions.append(b);
                    card.append(actions);
                }
                body.append(card);
            }
        }

        async function post(url, payload) {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': opts.csrfToken || '' },
                body: JSON.stringify(payload),
                credentials: 'same-origin',
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok && !data.error) data.error = { message: data.message || 'Erro ' + response.status + '.' };
            return data;
        }

        async function ask(text) {
            text = (text || '').trim();
            if (!text || busy) return;
            busy = true; send.disabled = true;
            body.querySelectorAll('.fxa-chips').forEach((c) => c.remove());
            body.append(el('div', 'fxa-msg user', text));
            const thinking = el('div', 'fxa-thinking', assistant.name + ' está pensando');
            body.append(thinking); scroll();

            const payload = Object.assign({ text: text, page: opts.getPage(), error: pendingError, mode: mode }, opts.extra());
            pendingError = null;
            let data;
            try { data = await post(opts.endpoint, payload); }
            catch (e) { data = { error: { message: 'Sem conexão. Tente de novo.' } }; }

            thinking.remove();
            busy = false; send.disabled = false;
            if (data.assistant) { assistant = Object.assign(assistant, normalize(data.assistant)); applyAssistant(); }
            applyUsage(data.usage_text);
            if (data.setup_required && opts.setup) { needsSetup = true; chooser(true); return; }
            render(data);
            opts.onAnswer(data, payload);
            scroll(); input.focus();
        }

        async function reset() {
            if (opts.resetEndpoint) {
                let data;
                try { data = await post(opts.resetEndpoint, Object.assign({ mode: mode }, opts.extra())); }
                catch (e) { data = { error: { message: 'Sem conexão. Tente de novo.' } }; }
                if (data.assistant) { assistant = Object.assign(assistant, normalize(data.assistant)); applyAssistant(); }
                applyUsage(data.usage_text);
                if (data.error) { greet(); body.append(el('div', 'fxa-msg error', data.error.message || 'Não foi possível começar agora.')); return; }
            }
            needsSetup ? chooser(true) : greet();
        }

        /** Ao reabrir (ex.: depois de trocar de tela), mostra o fim da conversa em andamento. */
        async function loadHistory() {
            if (!opts.historyEndpoint) return false;
            let data;
            try {
                const response = await fetch(opts.historyEndpoint, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                data = response.ok ? await response.json() : null;
            } catch (e) { data = null; }
            if (!data) return false;
            if (data.assistant) { assistant = Object.assign(assistant, normalize(data.assistant)); applyAssistant(); }
            applyUsage(data.usage_text);
            if (body.querySelector('.fxa-msg, .fxa-foxes')) return true; // a pessoa já começou enquanto carregava
            if (!data.messages || !data.messages.length) return false;
            body.innerHTML = '';
            body.append(el('div', 'fxa-note', 'Conversa recente'));
            data.messages.forEach((m) => body.append(m.role === 'user' ? el('div', 'fxa-msg user', m.text) : aiMessage(m.text)));
            scroll();
            return true;
        }

        async function open() {
            panel.hidden = false; launcher.hidden = true;
            input.focus();
            if (body.childElementCount) return;
            if (needsSetup) { chooser(true); return; }
            const loading = el('div', 'fxa-thinking', 'Abrindo');
            body.append(loading);
            const restored = await loadHistory();
            loading.remove();
            if (!restored && !body.querySelector('.fxa-msg, .fxa-foxes')) greet();
        }
        function close() { panel.hidden = true; launcher.hidden = false; launcher.focus(); }

        launcher.addEventListener('click', open);
        closeBtn.addEventListener('click', close);
        newBtn.addEventListener('click', reset);
        gearBtn.addEventListener('click', openSettings);
        // Trocar o modo começa uma conversa nova (cada conversa fica no modo em que começou).
        modeSelect.addEventListener('change', () => { mode = modeSelect.value; applyMode(); reset(); });
        panel.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
        form.addEventListener('submit', (e) => { e.preventDefault(); const t = input.value; input.value = ''; ask(t); });
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });

        applyAssistant();
        applyMode();
        applyUsage();
        if (opts.open) open();

        return {
            open, close, reset, ask,
            /** Atualiza a raposa mostrada (ex.: depois que a pessoa escolheu outra). */
            setAssistant(next) { assistant = Object.assign(assistant, normalize(next)); applyAssistant(); greet(); },
            /** Erro que apareceu na tela: vai junto com a próxima pergunta. */
            reportError(error) { pendingError = error; open(); },
            /** Abre a escolha da raposa (ex.: link "Trocar raposa" no menu do sistema). */
            chooseFox() { panel.hidden = false; launcher.hidden = true; closeSettings(); chooser(false); },
            /** Abre a tela ⚙ (raposa, modo e uso do mês). */
            openSettings() { panel.hidden = false; launcher.hidden = true; if (settingsView.hidden) openSettings(); },
        };
    }

    global.FoxyAssistant = { mount };
})(window);
