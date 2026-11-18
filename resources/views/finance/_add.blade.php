{{-- Botão roxo "+ ..." do topo da tela. Com $href, leva a outra tela; com $target, abre o formulário desta página
     (um <details> ou bloco com esse id), rola até ele e põe o cursor no primeiro campo. --}}
@if (! empty($href))
<a class="button" href="{{ $href }}">+ {{ $label }}</a>
@else
<a class="button" href="#{{ $target }}" data-add-target="{{ $target }}">+ {{ $label }}</a>
@endif
