{{-- Paginação padrão de todas as listas (Paginator::defaultView): em português e sem depender de Tailwind. --}}
@if ($paginator->hasPages())
    @php($last = $paginator->lastPage())
    @php($current = $paginator->currentPage())
    @php($pages = collect([1, $current - 1, $current, $current + 1, $last])->filter(fn ($p) => $p >= 1 && $p <= $last)->unique()->sort()->values())
    <nav class="core-pager" role="navigation" aria-label="Paginação">
        <span class="core-pager__info">Mostrando {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ number_format($paginator->total(), 0, ',', '.') }}</span>
        <span class="core-pager__links">
            @if ($paginator->onFirstPage())
                <span class="core-pager__item is-disabled" aria-disabled="true">‹ Anterior</span>
            @else
                <a class="core-pager__item" href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Anterior</a>
            @endif

            @foreach ($pages as $i => $page)
                @if ($i > 0 && $page - $pages[$i - 1] > 1)<span class="core-pager__gap">…</span>@endif
                @if ($page === $current)
                    <span class="core-pager__item is-current" aria-current="page">{{ $page }}</span>
                @else
                    <a class="core-pager__item" href="{{ $paginator->url($page) }}" aria-label="Página {{ $page }}">{{ $page }}</a>
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a class="core-pager__item" href="{{ $paginator->nextPageUrl() }}" rel="next">Próxima ›</a>
            @else
                <span class="core-pager__item is-disabled" aria-disabled="true">Próxima ›</span>
            @endif
        </span>
    </nav>
    <style>
        .core-pager{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;}
        .core-pager__info{color:var(--muted,#6b7280);font-size:.9rem;}
        .core-pager__links{display:flex;flex-wrap:wrap;gap:6px;align-items:center;}
        .core-pager__item{display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:36px;padding:0 12px;border-radius:10px;border:1px solid var(--border,#e5e7eb);background:#fff;color:inherit;text-decoration:none;font-size:.9rem;}
        a.core-pager__item:hover{border-color:var(--primary,#5b3fd6);color:var(--primary,#5b3fd6);}
        .core-pager__item.is-current{background:var(--primary,#5b3fd6);border-color:var(--primary,#5b3fd6);color:#fff;font-weight:600;}
        .core-pager__item.is-disabled{opacity:.45;cursor:default;}
        .core-pager__gap{padding:0 4px;color:var(--muted,#6b7280);}
    </style>
@endif
