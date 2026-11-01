/*
 * Janela da raposa (Foxy AI) — sem dependências. O navegador fala só com o servidor do próprio sistema
 * (options.endpoint); é ele que guarda a chave da Foxy AI e executa as consultas com as permissões da pessoa.
 *
 * Contrato do endpoint do sistema (ver docs/06-api-foxy-ai.md, seção "Janela da raposa"):
 *   POST endpoint      { text, page, error, ...extra() }  →  { reply, drafts, escalate, assistant, tools_used, error }
 *   POST resetEndpoint {}                                  →  { assistant }   (opcional: começa conversa nova)
 *
 * Uso:
 *   FoxyAssistant.mount({
 *     endpoint: '/assistente/mensagens', resetEndpoint: '/assistente/nova',
 *     csrfToken: '...', assistant: { name: 'Lud', avatarUrl: '/core/assistants/lud.svg' },
 *     suggestions: ['Como remarco um atendimento?'], getPage: () => document.title,
 *     draftActions: [{ label: 'Abrir no WhatsApp', onClick: (draft) => ... }],
 *     onAnswer: (data) => {}, extra: () => ({}),
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

    /** Aceita o formato da API (avatar_url) e o do JS (avatarUrl). */
    function normalize(a) {
        a = Object.assign({}, a || {});
        if (a.avatar_url && !a.avatarUrl) a.avatarUrl = a.avatar_url;
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
        }, options || {});

        let assistant = Object.assign({ name: 'Lud', article: 'a', avatarUrl: '/core/assistants/lud.svg' }, normalize(opts.assistant));
        let busy = false;
        let pendingError = null;

        // ----- Estrutura -----
        const launcher = el('button', 'fxa-launcher');
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
        const newBtn = el('button', 'fxa-icon-btn', 'Nova conversa'); newBtn.type = 'button';
        const closeBtn = el('button', 'fxa-icon-btn', '✕'); closeBtn.type = 'button'; closeBtn.setAttribute('aria-label', 'Fechar');
        head.append(headImg, who, newBtn, closeBtn);

        const body = el('div', 'fxa-body');
        body.setAttribute('aria-live', 'polite');

        const foot = el('div', 'fxa-foot');
        const form = el('form', 'fxa-form');
        const input = el('textarea');
        input.rows = 1;
        input.setAttribute('aria-label', 'Sua mensagem');
        const send = el('button', null, 'Enviar'); send.type = 'submit';
        form.append(input, send);
        foot.append(form, el('p', 'fxa-disclaimer', 'A IA pode errar. Confira antes de usar ou enviar.'));

        panel.append(head, body, foot);
        document.body.append(launcher, panel);

        function applyAssistant() {
            launcherImg.src = headImg.src = assistant.avatarUrl;
            launcherText.textContent = 'Pergunte ' + (assistant.article === 'o' ? 'ao ' : 'à ') + assistant.name;
            headName.textContent = assistant.name;
            panel.setAttribute('aria-label', 'Conversa com ' + assistant.name);
            input.placeholder = 'Pergunte ' + (assistant.article === 'o' ? 'ao ' : 'à ') + assistant.name + '…';
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
                body.append(el('div', 'fxa-msg error', data.error.message || 'Não foi possível responder agora.'));
                return;
            }
            if (data.tools_used && data.tools_used.length) {
                body.append(el('div', 'fxa-note', '🔎 Consultou: ' + data.tools_used.join(', ')));
            }
            if (data.reply) body.append(el('div', 'fxa-msg ai', data.reply));
            (data.drafts || []).forEach((d) => body.append(draftCard(d)));
            if (data.escalate) {
                const card = el('div', 'fxa-card escalate');
                card.append(el('div', 'fxa-card-title', 'Encaminhado ao suporte'), el('div', 'fxa-card-text', data.escalate.summary));
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

            const payload = Object.assign({ text: text, page: opts.getPage(), error: pendingError }, opts.extra());
            pendingError = null;
            let data;
            try { data = await post(opts.endpoint, payload); }
            catch (e) { data = { error: { message: 'Sem conexão. Tente de novo.' } }; }

            thinking.remove();
            if (data.assistant) { assistant = Object.assign(assistant, normalize(data.assistant)); applyAssistant(); }
            render(data);
            opts.onAnswer(data, payload);
            busy = false; send.disabled = false; scroll(); input.focus();
        }

        async function reset() {
            if (opts.resetEndpoint) {
                const data = await post(opts.resetEndpoint, opts.extra());
                if (data.assistant) { assistant = Object.assign(assistant, normalize(data.assistant)); applyAssistant(); }
            }
            greet();
        }

        function open() { panel.hidden = false; launcher.hidden = true; if (!body.childElementCount) greet(); input.focus(); }
        function close() { panel.hidden = true; launcher.hidden = false; launcher.focus(); }

        launcher.addEventListener('click', open);
        closeBtn.addEventListener('click', close);
        newBtn.addEventListener('click', reset);
        panel.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
        form.addEventListener('submit', (e) => { e.preventDefault(); const t = input.value; input.value = ''; ask(t); });
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });

        applyAssistant();
        if (opts.open) open();

        return {
            open, close, reset, ask,
            /** Atualiza a raposa mostrada (ex.: depois que a pessoa escolheu outra). */
            setAssistant(next) { assistant = Object.assign(assistant, normalize(next)); applyAssistant(); greet(); },
            /** Erro que apareceu na tela: vai junto com a próxima pergunta. */
            reportError(error) { pendingError = error; open(); },
        };
    }

    global.FoxyAssistant = { mount };
})(window);
