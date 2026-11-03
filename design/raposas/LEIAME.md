# Desenhos das raposas

**Geometria idêntica à da logo** (decidido em 2026-11-03; aparência a partir das fotos de referência dos cachorros do responsável): as quatro raposas usam as mesmas facetas, tons e contorno da logo da Foxy; muda só a cor (tom a tom) e o rosto/traço de cada uma:

| Raposa | Cores | Traço |
|---|---|---|
| Chico | preto | olhos castanhos, um dentinho de baixo para fora |
| Lud | escura (rajadinha) | focinho, queixo e sobrancelhas grisalhos, língua de fora, sem dentes à mostra |
| Lobinho | marrom e preto | rajado no rosto todo, focinho escuro, olhos cor de mel, língua de fora |
| Ayla | caramelo | focinho clarinho, olhos grandes, bochechas coradas, lenço rosado no pescoço |

Três estilos à escolha de cada pessoa: **facetas** (padrão, igual à logo), **linhas** (só os traços da malha, fundo claro) e **neon** (malha luminosa no escuro).

## Gerar

```bash
php design/raposas/gerar.php public/core/assistants
```

Gera `{estilo}/{raposa}.svg` e `{raposa}.svg` (estilo padrão). Os sistemas recebem o endereço das imagens pela API (`avatar_url`): trocar o arquivo não exige mudança em nenhum sistema. Cores e traços ficam no topo de `gerar.php`.

## Se a logo mudar

A geometria vem do próprio PNG da logo (`public/core/brand/logo.png`), em `logo/`:

```bash
php design/raposas/logo/extrair.php   # facetas (cor + vértices) → logo-geo.json
php design/raposas/logo/montar.php    # junta vértices, contorno e fundo sem frestas → geometria.json (+ reproducao.svg para conferir)
php design/raposas/gerar.php public/core/assistants
```

O contorno (`montar.php`) foi medido na faixa lilás da logo com `logo/contorno.php`; se o formato da cabeça mudar, meça de novo e ajuste os pontos.
