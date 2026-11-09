<x-core::layout title="WhatsApp da loja" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions>@include('commerce.export.buttons')</x-slot:actions>

    @include('commerce.status')
    @php($status = $state['status'] ?? 'unknown')
    @php($connected = $status === 'open')

    <div class="card">
        <div class="toolbar">
            <h3 style="margin:0;">Conexão</h3>
            <span @class(['badge', 'success' => $connected, 'danger' => ! $connected])>{{ match ($status) { 'open' => 'Conectado', 'connecting' => 'Conectando…', 'not_configured' => 'Indisponível no momento', 'offline', 'error' => 'Servidor do WhatsApp sem resposta', default => 'Desconectado' } }}</span>
        </div>
        @if ($connected)
            <p>O número da loja está conectado: cobranças e lembretes saem por ele, como se você tivesse mandado do celular.</p>
            <form method="POST" action="{{ route('commerce.whatsapp.disconnect') }}" onsubmit="return confirm('Desconectar o WhatsApp da loja? Os lembretes automáticos param.');">@csrf<button type="submit" class="secondary">Desconectar</button></form>
        @elseif ($status === 'not_configured')
            <p class="helper">A conexão do WhatsApp ainda não está disponível. Enquanto isso, o botão "Cobrar" abre o seu WhatsApp com a mensagem pronta.</p>
        @else
            <ol class="helper" style="line-height:1.8;">
                <li>Clique em <b>Gerar QR Code</b>.</li>
                <li>No celular da loja, abra o WhatsApp › <b>Aparelhos conectados</b> › <b>Conectar um aparelho</b>.</li>
                <li>Aponte a câmera para o QR Code. Pronto: esta tela muda para "Conectado".</li>
            </ol>
            <button type="button" id="wa-connect">Gerar QR Code</button>
            <div id="wa-qr" style="margin-top:16px;"></div>
            <p class="helper">Sem conectar, tudo continua funcionando: o botão "Cobrar" abre o seu WhatsApp com o texto pronto. Os lembretes automáticos precisam da conexão.</p>
            <script>
                (function () {
                    const button = document.getElementById('wa-connect'), box = document.getElementById('wa-qr');
                    const token = document.querySelector('meta[name="csrf-token"]')?.content || @json(csrf_token());
                    let timer;
                    button.addEventListener('click', async function () {
                        button.disabled = true; box.textContent = 'Gerando o QR Code… pode levar alguns segundos.';
                        try {
                            const res = await fetch(@json(route('commerce.whatsapp.connect')), { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } });
                            const data = await res.json();
                            if (data.open) { location.reload(); return; }
                            if (data.error) { box.textContent = data.error; button.disabled = false; return; }
                            box.innerHTML = '';
                            const img = document.createElement('img');
                            img.src = data.qr.startsWith('data:') ? data.qr : 'data:image/png;base64,' + data.qr;
                            img.alt = 'QR Code do WhatsApp'; img.style.cssText = 'width:260px;height:260px;border:1px solid var(--border);border-radius:12px;';
                            box.appendChild(img);
                            const note = document.createElement('div'); note.className = 'helper'; note.textContent = 'Escaneie com o celular da loja. O QR vence em cerca de 1 minuto: se vencer, gere outro.';
                            box.appendChild(note);
                            button.disabled = false; button.textContent = 'Gerar outro QR Code';
                            clearInterval(timer);
                            timer = setInterval(async function () {
                                const s = await (await fetch(@json(route('commerce.whatsapp.status')), { headers: { 'Accept': 'application/json' } })).json();
                                if (s.status === 'open') { clearInterval(timer); location.reload(); }
                            }, 4000);
                        } catch (e) { box.textContent = 'Não deu para falar com o servidor do WhatsApp. Tente de novo.'; button.disabled = false; }
                    });
                })();
            </script>
        @endif
    </div>

    <form class="card stack" method="POST" action="{{ route('commerce.whatsapp.settings') }}" style="margin-top:16px;">
        @csrf
        <h3 style="margin:0;">Lembretes automáticos das contas a receber</h3>
        <label style="display:flex;gap:8px;align-items:center;font-weight:600;"><input type="checkbox" name="reminders" value="1" style="width:auto;" @checked($settings['reminders'])> Mandar lembretes sozinho pelo WhatsApp da loja</label>
        <div class="form-grid" style="align-items:end;">
            <div><label for="days_before">Dias antes do vencimento</label><input id="days_before" name="days_before" type="number" min="0" max="30" value="{{ $settings['days_before'] }}"><div class="helper">0 = não avisar antes</div></div>
            <div style="display:flex;align-items:center;"><label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="checkbox" name="on_due" value="1" style="width:auto;" @checked($settings['on_due'])> No dia do vencimento</label></div>
            <div><label for="after_days">Depois de vencer (dias)</label><input id="after_days" name="after_days" value="{{ implode(', ', $settings['after_days']) }}" placeholder="1, 7"><div class="helper">Ex.: "1, 7" = no dia seguinte e uma semana depois</div></div>
        </div>
        <p class="helper" style="margin:0;">Saem entre 9h e 19h, uma vez por etapa, com uma pausa entre as mensagens (para o número não ser bloqueado). Só para clientes com WhatsApp cadastrado.</p>

        <h3 style="margin:8px 0 0;">Textos das mensagens</h3>
        <p class="helper" style="margin:0;">Pode usar: <code>{cliente}</code> <code>{loja}</code> <code>{descricao}</code> <code>{valor}</code> <code>{vencimento}</code> <code>{pix}</code> (a chave e o Pix copia e cola com o valor certo, se o Pix estiver preenchido em Loja própria). Apague o texto para voltar ao padrão.</p>
        <div class="grid two">
            @foreach (\App\Commerce\Finance\WhatsAppMessage::KINDS as $kind => $label)
                <div><label for="t-{{ $kind }}">{{ $label }}</label><textarea id="t-{{ $kind }}" name="templates[{{ $kind }}]" rows="6" maxlength="1500">{{ $settings['templates'][$kind] }}</textarea></div>
            @endforeach
        </div>
        <div><button type="submit">Salvar</button></div>
    </form>

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Mensagens enviadas</h3>
        @if ($messages->isEmpty())
            <div class="empty">Nenhuma mensagem ainda. As cobranças e os lembretes aparecem aqui.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Quando</th><th>Cliente</th><th>Tipo</th><th>Situação</th><th>Mensagem</th></tr></thead>
                <tbody>
                    @foreach ($messages as $m)
                        <tr>
                            <td style="white-space:nowrap;">{{ $m->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</td>
                            <td>{{ $m->customer?->name ?? '—' }}<div class="helper">{{ $m->phone }}</div></td>
                            <td>{{ \App\Commerce\Finance\WhatsAppMessage::KINDS[$m->kind] ?? $m->kind }}</td>
                            <td><span @class(['badge', 'success' => $m->status === 'enviado', 'danger' => $m->status === 'falhou'])>{{ \App\Commerce\Finance\WhatsAppMessage::STATUSES[$m->status] ?? $m->status }}</span>@if ($m->error)<div class="helper">{{ $m->error }}</div>@endif</td>
                            <td class="helper" style="max-width:420px;white-space:pre-line;">{{ \Illuminate\Support\Str::limit($m->text, 180) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div style="margin-top:12px;">{{ $messages->links() }}</div>
        @endif
    </div>
</x-core::layout>
