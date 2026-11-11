<x-core::layout title="Fotos em lote" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions><a class="button secondary" href="{{ route('commerce.imports') }}">Voltar para Importar</a></x-slot:actions>

    @include('commerce.status')

    <div class="card stack">
        <h3 style="margin:0;">Escolha as fotos (pode ser muitas de uma vez)</h3>
        <p class="helper" style="margin:0;">Antes, renomeie cada foto com o <b>nome do produto</b> — e a <b>cor</b>, se a foto for de uma cor só. O sistema acha o produto e marca a cor sozinho:</p>
        <ul class="helper" style="margin:0;padding-left:18px;">
            <li><code>Sutiã Renda Floral Preto.jpg</code> → Sutiã Renda Floral, foto da cor Preto (aparece quando a cliente escolhe Preto)</li>
            <li><code>Sutiã Renda Floral Preto 2.jpg</code> → mais uma foto da mesma cor</li>
            <li><code>Calcinha Fio Dental.jpg</code> → foto que vale para todas as cores</li>
            <li><code>SUT-REN-44-PRETO.jpg</code> ou <code>7891234567890.jpg</code> → pelo SKU ou código de barras</li>
        </ul>
        <div id="drop" style="border:2px dashed var(--border-strong);border-radius:16px;padding:28px;text-align:center;">
            <input id="photos" type="file" accept="image/jpeg,image/png,image/webp" multiple style="width:auto;max-width:100%;">
            <div class="helper">ou arraste as fotos para cá · JPG, PNG ou WEBP até 5 MB cada · até {{ $max }} fotos por produto</div>
        </div>
        <div id="progress" class="helper" style="margin:0;" aria-live="polite"></div>
    </div>

    <div class="card" id="result-card" style="margin-top:16px;display:none;">
        <div class="table-wrap"><table>
            <thead><tr><th>Arquivo</th><th>Produto</th><th>Vale para</th></tr></thead>
            <tbody id="result"></tbody>
        </table></div>
    </div>

    <script>
    (function () {
        const input = document.getElementById('photos'), drop = document.getElementById('drop');
        const progress = document.getElementById('progress'), result = document.getElementById('result'), card = document.getElementById('result-card');
        const token = document.querySelector('meta[name=csrf-token]').content;
        const endpoint = @json(route('commerce.imports.photo'));
        let busy = false;

        function row(name, product, option, error) {
            const tr = document.createElement('tr');
            const a = document.createElement('td'); a.textContent = name;
            const b = document.createElement('td');
            if (product) { const link = document.createElement('a'); link.href = product.url; link.textContent = product.name; b.appendChild(link); }
            else { const s = document.createElement('span'); s.className = 'badge danger'; s.textContent = error; b.appendChild(s); }
            const c = document.createElement('td'); c.textContent = option || '';
            tr.append(a, b, c); result.appendChild(tr); card.style.display = "";
        }

        async function send(files) {
            if (busy || !files.length) return;
            busy = true; input.disabled = true;
            let ok = 0, fail = 0;
            for (let i = 0; i < files.length; i++) {
                progress.textContent = 'Enviando ' + (i + 1) + ' de ' + files.length + '…';
                const body = new FormData(); body.append('image', files[i]);
                try {
                    const res = await fetch(endpoint, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }, body });
                    const data = await res.json().catch(() => ({}));
                    if (res.ok && data.ok) { ok++; row(files[i].name, { name: data.product, url: data.url }, data.option); }
                    else { fail++; row(files[i].name, null, '', data.message || (data.errors && Object.values(data.errors)[0][0]) || 'Não deu para enviar.'); }
                } catch (e) { fail++; row(files[i].name, null, '', 'Falha de conexão. Tente de novo.'); }
            }
            progress.textContent = ok + ' foto(s) enviada(s)' + (fail ? ', ' + fail + ' não reconhecida(s) — renomeie e envie de novo, ou envie pela tela do produto.' : '.');
            busy = false; input.disabled = false; input.value = '';
        }

        input.addEventListener('change', () => send(Array.from(input.files)));
        ['dragover', 'dragenter'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.style.borderColor = 'var(--primary)'; }));
        ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.style.borderColor = ''; }));
        drop.addEventListener('drop', (e) => send(Array.from(e.dataTransfer.files).filter((f) => /^image\//.test(f.type))));
    })();
    </script>
</x-core::layout>
