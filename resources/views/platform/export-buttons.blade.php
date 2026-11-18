{{-- Botões de exportação: levam os mesmos filtros da tela (sem a página: exporta tudo o que foi filtrado). --}}
@php($exportQuery = array_merge(request()->except(['page', 'exportar']), $extra ?? []))
<a class="button secondary" href="{{ url()->current().'?'.http_build_query($exportQuery + ['exportar' => 'planilha']) }}" title="Baixar planilha (abre no Excel)">Planilha</a>
<a class="button secondary" href="{{ url()->current().'?'.http_build_query($exportQuery + ['exportar' => 'pdf']) }}" title="Baixar PDF">PDF</a>
