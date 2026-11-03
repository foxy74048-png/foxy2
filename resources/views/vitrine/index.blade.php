@php
    $studio = rtrim(config('foxy.products.studio_url'), '/');
    $foxes = [
        ['id' => 'lud', 'name' => 'Lud', 'jeito' => 'Acolhedora e paciente', 'fala' => 'Vamos juntos: abra a Agenda, clique no atendimento e escolha "Remarcar".'],
        ['id' => 'chico', 'name' => 'Chico', 'jeito' => 'Objetivo e direto', 'fala' => 'Hoje: 6 atendimentos. Primeiro às 9h, com a Ana.'],
        ['id' => 'lobinho', 'name' => 'Lobinho', 'jeito' => 'Animado e leve', 'fala' => 'Bora! 3 clientes estão sumidos há 60 dias — escrevo um convite? 🙂'],
        ['id' => 'ayla', 'name' => 'Ayla', 'jeito' => 'Formal e cuidadosa', 'fala' => 'O valor em aberto é de R$ 320,00, da cliente Carla. Deseja que eu sugira uma mensagem?'],
    ];
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Foxy — tecnologia para quem cuida de gente</title>
    <meta name="description" content="A Foxy cria sistemas para negócios de atendimento: o Foxy Studio (agenda, clientes, financeiro e WhatsApp), a Foxy AI (a raposa que ajuda no dia a dia) e, em breve, o Foxy Commerce.">
    <meta property="og:title" content="Foxy — tecnologia para quem cuida de gente">
    <meta property="og:description" content="Foxy Studio, Foxy AI e Foxy Commerce: tudo o que um negócio de atendimento precisa, num lugar só.">
    <meta property="og:image" content="{{ url('/core/brand/logo.png') }}">
    <link rel="icon" href="/favicon.ico">
    <style>
        :root { --primary: #5b3fd1; --primary-dark: #4527a0; --ink: #1f1b2d; --muted: #6b6880; --night: #221a33; --bg: #f5f5f8; --line: rgba(91, 63, 209, 0.16); --soft: rgba(91, 63, 209, 0.08); }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { margin: 0; font: 16px/1.6 "Segoe UI", Inter, Arial, sans-serif; color: var(--ink); background: #fff; }
        a { color: inherit; }
        .wrap { max-width: 1120px; margin: 0 auto; padding: 0 24px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 13px 22px; border-radius: 12px; background: var(--primary); color: #fff; text-decoration: none; font-weight: 600; border: 1px solid var(--primary); }
        .btn:hover { background: var(--primary-dark); }
        .btn.ghost { background: transparent; color: var(--primary-dark); border-color: var(--line); }
        .btn.ghost:hover { background: var(--soft); }
        .btn.light { background: #fff; color: var(--night); border-color: #fff; }
        .eyebrow { text-transform: uppercase; letter-spacing: 0.18em; font-size: 12px; font-weight: 700; color: var(--primary); }
        h1, h2, h3 { line-height: 1.15; margin: 0 0 12px; }
        h1 { font-size: clamp(34px, 5vw, 56px); letter-spacing: -0.02em; }
        h2 { font-size: clamp(26px, 3.4vw, 38px); letter-spacing: -0.01em; }
        h3 { font-size: 20px; }
        p { margin: 0 0 12px; }
        .muted { color: var(--muted); }

        header.top { position: sticky; top: 0; z-index: 10; background: rgba(255, 255, 255, 0.92); backdrop-filter: blur(8px); border-bottom: 1px solid var(--line); }
        header.top .wrap { display: flex; align-items: center; gap: 24px; height: 68px; }
        .brand { display: flex; align-items: center; gap: 10px; text-decoration: none; font-family: Georgia, Cambria, serif; letter-spacing: 0.22em; font-size: 20px; font-weight: 600; color: var(--night); }
        .brand img { width: 38px; height: 38px; }
        nav.links { display: flex; gap: 22px; margin-left: auto; font-size: 15px; }
        nav.links a { text-decoration: none; color: var(--muted); }
        nav.links a:hover { color: var(--ink); }
        .top-cta { display: flex; gap: 10px; }
        .top-cta .btn { padding: 9px 16px; font-size: 14px; }
        @media (max-width: 860px) { nav.links { display: none; } .top-cta { margin-left: auto; } .top-cta .ghost { display: none; } }

        .hero { background: radial-gradient(1200px 500px at 80% -10%, rgba(139, 108, 245, 0.18), transparent), linear-gradient(180deg, #fbfaff, #fff); padding: 72px 0 56px; }
        .hero .wrap { display: grid; grid-template-columns: 1.15fr 0.85fr; gap: 40px; align-items: center; }
        .hero p.lead { font-size: 19px; color: var(--muted); max-width: 560px; }
        .hero .ctas { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 22px; }
        .hero .note { margin-top: 14px; font-size: 14px; color: var(--muted); }
        .hero-art { position: relative; aspect-ratio: 1; max-width: 420px; justify-self: center; width: 100%; }
        .hero-art .logo { position: absolute; inset: 12%; width: 76%; filter: drop-shadow(0 24px 40px rgba(34, 26, 51, 0.25)); }
        .hero-art .fox { position: absolute; width: 26%; border-radius: 50%; background: #fff; box-shadow: 0 12px 30px rgba(34, 26, 51, 0.18); }
        @media (max-width: 860px) { .hero .wrap { grid-template-columns: 1fr; } .hero-art { max-width: 300px; } }

        section { padding: 72px 0; }
        section.alt { background: var(--bg); }
        .head { max-width: 720px; margin-bottom: 36px; }
        .grid3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
        .grid4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; }
        .grid2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
        @media (max-width: 960px) { .grid3, .grid4 { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 620px) { .grid3, .grid4, .grid2 { grid-template-columns: 1fr; } }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 20px; padding: 26px; display: flex; flex-direction: column; gap: 10px; }
        .card ul { margin: 4px 0 8px; padding-left: 20px; color: var(--muted); }
        .card ul li { margin: 3px 0; }
        .card .actions { margin-top: auto; display: flex; gap: 10px; flex-wrap: wrap; padding-top: 8px; }
        .tag { align-self: flex-start; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px; background: var(--soft); color: var(--primary-dark); }
        .tag.soon { background: #fff7ed; color: #9a3412; }
        .product-icon { width: 48px; height: 48px; border-radius: 14px; background: var(--soft); display: grid; place-items: center; font-size: 24px; }

        .fox-card { text-align: center; align-items: center; }
        .fox-card img { width: 120px; height: 120px; }
        .fox-card .fala { font-size: 14px; background: var(--bg); border-radius: 14px; padding: 10px 12px; color: var(--ink); }

        .feature { display: flex; gap: 14px; align-items: flex-start; }
        .feature .ic { flex: 0 0 auto; width: 40px; height: 40px; border-radius: 12px; background: var(--soft); display: grid; place-items: center; font-size: 20px; }
        .feature h3 { font-size: 17px; margin-bottom: 4px; }
        .feature p { color: var(--muted); font-size: 15px; margin: 0; }

        .band { background: var(--night); color: #fff; border-radius: 28px; padding: 48px; display: grid; grid-template-columns: 1fr auto; gap: 24px; align-items: center; }
        .band p { color: rgba(255, 255, 255, 0.75); }
        @media (max-width: 760px) { .band { grid-template-columns: 1fr; padding: 32px; } }

        footer { border-top: 1px solid var(--line); padding: 36px 0; color: var(--muted); font-size: 14px; }
        footer .wrap { display: flex; flex-wrap: wrap; gap: 18px; justify-content: space-between; align-items: center; }
        footer nav { display: flex; gap: 18px; flex-wrap: wrap; }
        footer a { text-decoration: none; }
        footer a:hover { color: var(--ink); }
    </style>
</head>
<body>
    <header class="top">
        <div class="wrap">
            <a class="brand" href="/"><img src="/core/brand/logo.png" alt="">FOXY</a>
            <nav class="links" aria-label="Seções">
                <a href="#produtos">Produtos</a>
                <a href="#raposa">A raposa</a>
                <a href="#porque">Por que a Foxy</a>
                <a href="#seguranca">Segurança</a>
            </nav>
            <div class="top-cta">
                <a class="btn ghost" href="{{ $studio }}/entrar">Entrar</a>
                <a class="btn" href="{{ $studio }}/cadastro">Começar grátis</a>
            </div>
        </div>
    </header>

    <main>
        <section class="hero">
            <div class="wrap">
                <div>
                    <div class="eyebrow">Foxy · tecnologia para negócios de atendimento</div>
                    <h1>Você cuida de gente.<br>A Foxy cuida do resto.</h1>
                    <p class="lead">Sistemas feitos para estética, saúde, terapias, beleza e bem-estar: agenda online, clientes, fichas, financeiro, WhatsApp automático e uma assistente de IA que entende o seu dia a dia.</p>
                    <div class="ctas">
                        <a class="btn" href="{{ $studio }}/cadastro">Começar grátis no Foxy Studio</a>
                        <a class="btn ghost" href="#produtos">Conhecer os produtos</a>
                    </div>
                    <p class="note">Teste grátis, sem compromisso. Você só assina se fizer sentido.</p>
                </div>
                <div class="hero-art" aria-hidden="true">
                    <img class="logo" src="/core/brand/logo.png" alt="">
                    <img class="fox" src="/core/assistants/facetas/lud.svg" alt="" style="left:-4%; top:6%;">
                    <img class="fox" src="/core/assistants/facetas/chico.svg" alt="" style="right:-4%; top:2%;">
                    <img class="fox" src="/core/assistants/facetas/ayla.svg" alt="" style="left:2%; bottom:0;">
                    <img class="fox" src="/core/assistants/facetas/lobinho.svg" alt="" style="right:0; bottom:4%;">
                </div>
            </div>
        </section>

        <section id="produtos">
            <div class="wrap">
                <div class="head">
                    <div class="eyebrow">Produtos</div>
                    <h2>Um ecossistema, cada produto no seu papel</h2>
                    <p class="muted">Comece pelo que você precisa hoje. Os produtos da Foxy conversam entre si, com a mesma conta e o mesmo cuidado com os seus dados.</p>
                </div>
                <div class="grid3">
                    <article class="card">
                        <div class="product-icon">📅</div>
                        <span class="tag">Disponível</span>
                        <h3>Foxy Studio</h3>
                        <p class="muted">A gestão completa de quem atende com hora marcada.</p>
                        <ul>
                            <li>Agenda online com confirmação e lembrete no WhatsApp</li>
                            <li>Ficha do cliente, anamnese e contrato com assinatura</li>
                            <li>Financeiro, cobranças, orçamentos e estoque</li>
                            <li>Equipe com permissões e site profissional</li>
                        </ul>
                        <div class="actions">
                            <a class="btn" href="{{ $studio }}/cadastro">Testar grátis</a>
                            <a class="btn ghost" href="{{ $studio }}/">Conhecer</a>
                        </div>
                    </article>
                    <article class="card">
                        <div class="product-icon">🦊</div>
                        <span class="tag">Dentro do Foxy Studio</span>
                        <h3>Foxy AI</h3>
                        <p class="muted">A raposa: uma assistente que entende o sistema e os seus números.</p>
                        <ul>
                            <li>Tira dúvidas de uso e explica erros na hora</li>
                            <li>"Quem me deve?", "Quem eu atendo hoje?", "Como foi meu mês?"</li>
                            <li>Escreve lembretes e mensagens para você revisar e enviar</li>
                            <li>Só lê e sugere: quem decide e envia é você</li>
                        </ul>
                        <div class="actions">
                            <a class="btn ghost" href="#raposa">Conhecer as raposas</a>
                        </div>
                    </article>
                    <article class="card">
                        <div class="product-icon">🛍️</div>
                        <span class="tag soon">Em breve</span>
                        <h3>Foxy Commerce</h3>
                        <p class="muted">Para vender produtos e pacotes, no balcão e online.</p>
                        <ul>
                            <li>Catálogo, pedidos e estoque num lugar só</li>
                            <li>Ligado aos seus clientes e ao financeiro</li>
                            <li>Com a raposa ajudando a vender melhor</li>
                        </ul>
                        <div class="actions"><span class="muted" style="font-size:14px;">Estamos construindo. Quem já usa o Foxy Studio fica sabendo primeiro.</span></div>
                    </article>
                </div>
            </div>
        </section>

        <section id="raposa" class="alt">
            <div class="wrap">
                <div class="head">
                    <div class="eyebrow">Foxy AI</div>
                    <h2>Escolha a sua raposa</h2>
                    <p class="muted">Quatro jeitos de conversar, a mesma precisão. Cada pessoa da equipe escolhe a sua, dá um nome se quiser e escolhe o estilo do desenho. Elas nascem da mesma geometria do logo da Foxy.</p>
                </div>
                <div class="grid4">
                    @foreach ($foxes as $fox)
                        <div class="card fox-card">
                            <img src="/core/assistants/facetas/{{ $fox['id'] }}.svg" alt="Raposa {{ $fox['name'] }}" loading="lazy">
                            <h3 style="margin:0;">{{ $fox['name'] }}</h3>
                            <div class="muted" style="font-size:14px;">{{ $fox['jeito'] }}</div>
                            <div class="fala">“{{ $fox['fala'] }}”</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section id="porque">
            <div class="wrap">
                <div class="head">
                    <div class="eyebrow">Por que a Foxy</div>
                    <h2>Feita para o dia a dia de quem atende</h2>
                </div>
                <div class="grid2">
                    <div class="feature"><div class="ic">🎯</div><div><h3>Pensada para saúde, beleza e bem-estar</h3><p>Anamnese, evolução, pacotes, retorno, antes e depois: o vocabulário e as telas do seu negócio, sem adaptar sistema genérico.</p></div></div>
                    <div class="feature"><div class="ic">💬</div><div><h3>WhatsApp que trabalha por você</h3><p>Confirmação, lembrete, aniversário e cobrança gentil saem sozinhos — você escolhe o que liga.</p></div></div>
                    <div class="feature"><div class="ic">🔗</div><div><h3>Tudo conectado</h3><p>Do primeiro contato pelo seu site ao histórico financeiro: o agendamento vira atendimento, o atendimento vira cobrança, a cobrança vira relatório.</p></div></div>
                    <div class="feature"><div class="ic">🦊</div><div><h3>IA com responsabilidade</h3><p>A raposa consulta só o que cada pessoa já pode ver e nunca muda nada sozinha. Você no controle, sempre.</p></div></div>
                    <div class="feature"><div class="ic">📱</div><div><h3>No computador e no celular</h3><p>Funciona no navegador e vai para a tela do celular como um app, sem instalar nada.</p></div></div>
                    <div class="feature"><div class="ic">🤝</div><div><h3>Gente de verdade no suporte</h3><p>Dúvida que a raposa não resolve vai para a nossa equipe, pelo chat de suporte dentro do sistema.</p></div></div>
                </div>
            </div>
        </section>

        <section id="seguranca" class="alt">
            <div class="wrap">
                <div class="head">
                    <div class="eyebrow">Segurança e LGPD</div>
                    <h2>Os dados dos seus clientes são seus</h2>
                </div>
                <div class="grid3">
                    <div class="card"><h3>🔒 Cada conta isolada</h3><p class="muted">Seus dados ficam separados dos de qualquer outra conta, e cada pessoa da equipe vê só o que você liberar.</p></div>
                    <div class="card"><h3>🩺 Saúde protegida</h3><p class="muted">A raposa não lê fichas de anamnese, evolução, fotos nem CPF. Conversas com a IA são apagadas automaticamente.</p></div>
                    <div class="card"><h3>📦 Seus dados, quando quiser</h3><p class="muted">Cópia completa dos seus dados a qualquer momento, com backups diários e exclusão quando pedir.</p></div>
                </div>
            </div>
        </section>

        <section>
            <div class="wrap">
                <div class="band">
                    <div>
                        <h2 style="color:#fff;">Comece hoje pelo Foxy Studio</h2>
                        <p>Crie sua conta em minutos, traga seus clientes de planilha ou de outro sistema e veja a raposa trabalhando com você.</p>
                    </div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        <a class="btn light" href="{{ $studio }}/cadastro">Começar grátis</a>
                        <a class="btn ghost" style="color:#fff; border-color:rgba(255,255,255,0.3);" href="{{ $studio }}/entrar">Já tenho conta</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer>
        <div class="wrap">
            <div>© {{ now()->year }} Foxy · tecnologia para quem cuida de gente</div>
            <nav aria-label="Rodapé">
                <a href="{{ $studio }}/">Foxy Studio</a>
                <a href="#raposa">Foxy AI</a>
                <a href="{{ $studio }}/privacidade">Privacidade</a>
                <a href="{{ $studio }}/entrar">Entrar</a>
            </nav>
        </div>
    </footer>
</body>
</html>
