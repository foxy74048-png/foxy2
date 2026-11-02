# Desenhos das raposas

Três estilos à escolha de cada pessoa (definido em 2026-11-02): **facetas** (padrão, irmã do logo), **linhas** (traços claros) e **neon** (mais tecnológico).

Para ajustar cores ou acessórios: edite `gerar.php` e rode

```bash
php design/raposas/gerar.php /caminho/de/saida
```

(ou direto em `public/core/assistants`), que gera `{estilo}/{raposa}.svg` e `{raposa}.svg` (estilo padrão). Os sistemas recebem o endereço das imagens pela API (`avatar_url`), então trocar o arquivo não exige mudança em nenhum sistema.
