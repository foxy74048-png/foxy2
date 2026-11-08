{{-- Estilo e dica (tooltip) dos gráficos do painel. Uma série por gráfico, na cor da marca; barras ≤ 24px com
     ponta de 4px arredondada; grade e eixos discretos; todo número também está na tabela ("Ver números"). --}}
<style>
.viz { --viz-1: var(--primary, #5b3fd1); --viz-1-hover: var(--primary-dark, #4730b0); --viz-grid: #ecebf3; --viz-muted: #6b6a75; }
.viz-cols { position: relative; display: grid; grid-template-columns: 64px 1fr; gap: 0 8px; }
.viz-cols__axis { position: relative; height: 220px; font-size: .75rem; color: var(--viz-muted); }
.viz-cols__axis span { position: absolute; right: 0; transform: translateY(50%); white-space: nowrap; }
.viz-cols__plot { position: relative; height: 220px; display: flex; align-items: flex-end; gap: 2px; border-bottom: 1px solid #d9d7e3; }
.viz-cols__grid { position: absolute; left: 0; right: 0; border-top: 1px dashed var(--viz-grid); pointer-events: none; }
.viz-col { flex: 1; height: 100%; display: flex; align-items: flex-end; justify-content: center; outline: none; cursor: default; }
.viz-col i { position: relative; z-index: 1; display: block; width: min(24px, 80%); min-height: 0; background: var(--viz-1); border-radius: 4px 4px 0 0; }
.viz-col:hover i, .viz-col:focus-visible i { background: var(--viz-1-hover); }
.viz-col:focus-visible { box-shadow: inset 0 0 0 2px var(--viz-1); border-radius: 4px; }
.viz-cols__labels { grid-column: 2; display: flex; gap: 2px; font-size: .72rem; color: var(--viz-muted); margin-top: 6px; }
.viz-cols__labels span { flex: 1; text-align: center; overflow: hidden; white-space: nowrap; }
.viz-hbars { display: grid; gap: 10px; }
.viz-hbar { display: grid; grid-template-columns: minmax(90px, 32%) 1fr auto; align-items: center; gap: 10px; outline: none; }
.viz-hbar__label { font-size: .88rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.viz-hbar__track { height: 14px; }
.viz-hbar__track i { display: block; height: 14px; background: var(--viz-1); border-radius: 0 4px 4px 0; min-width: 2px; }
.viz-hbar:hover i, .viz-hbar:focus-visible i { background: var(--viz-1-hover); }
.viz-hbar__value { font-weight: 600; font-size: .88rem; text-align: right; white-space: nowrap; }
.viz-hbar__value small { display: block; font-weight: 400; color: var(--viz-muted); font-size: .75rem; }
.viz-tip { position: fixed; z-index: 50; pointer-events: none; background: #1f1b2e; color: #fff; padding: 8px 10px; border-radius: 8px; font-size: .8rem; line-height: 1.4; max-width: 260px; box-shadow: 0 6px 18px rgba(0,0,0,.18); }
.viz-tip strong { display: block; font-size: .95rem; }
.viz-table summary { cursor: pointer; color: var(--viz-muted); font-size: .85rem; margin-top: 10px; }
.kpis { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
.kpi { padding: 16px 18px; }
.kpi__label { color: var(--viz-muted, #6b6a75); font-size: .85rem; }
.kpi__value { font-size: 1.6rem; font-weight: 700; margin: 4px 0 2px; letter-spacing: -.01em; }
.kpi__note { font-size: .8rem; color: #6b6a75; }
.kpi__delta { font-weight: 600; }
.kpi__delta.up { color: var(--success-text, #15803d); }
.kpi__delta.down { color: var(--danger-text, #b91c1c); }
.filters-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.filters-row > div { min-width: 150px; flex: 1; }
.filters-row .actions { flex: 0 0 auto; display: flex; gap: 8px; }
.section-title { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; margin: 0 0 14px; }
.section-title h3 { margin: 0; }
.num { text-align: right; white-space: nowrap; }
</style>
<script>
(() => {
    // Dica única: mostra data-tip-title (forte) e data-tip (linhas). Só textContent, nunca HTML.
    let tip;
    const show = (el, x, y) => {
        tip ??= Object.assign(document.createElement('div'), { className: 'viz-tip', role: 'tooltip' });
        if (!tip.isConnected) document.body.appendChild(tip);
        tip.replaceChildren();
        const strong = document.createElement('strong');
        strong.textContent = el.dataset.tipTitle || '';
        tip.appendChild(strong);
        (el.dataset.tip || '').split('|').forEach(line => { const d = document.createElement('div'); d.textContent = line; tip.appendChild(d); });
        const r = tip.getBoundingClientRect();
        tip.style.left = Math.min(x + 14, innerWidth - r.width - 8) + 'px';
        tip.style.top = Math.max(8, y - r.height - 12) + 'px';
        tip.hidden = false;
    };
    const hide = () => { if (tip) tip.hidden = true; };
    document.addEventListener('pointermove', e => { const el = e.target.closest('[data-tip-title]'); el ? show(el, e.clientX, e.clientY) : hide(); });
    document.addEventListener('focusin', e => { const el = e.target.closest('[data-tip-title]'); if (!el) return hide(); const r = el.getBoundingClientRect(); show(el, r.left + r.width / 2, r.top); });
    document.addEventListener('focusout', hide);
})();
</script>
