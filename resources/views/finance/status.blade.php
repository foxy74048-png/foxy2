{{-- Avisos no topo das telas do app (sucesso e erro). A situação do plano entra aqui na fatia 10. --}}
@if (session('status'))<div class="alert success" style="margin-bottom:16px;">{{ session('status') }}</div>@endif
@if ($errors->any())<div class="alert error" style="margin-bottom:16px;">{{ $errors->first() }}</div>@endif
