<form method="POST" action="{{ route('commerce.imports.upload', $type) }}" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
    @csrf
    <div style="flex:1;min-width:200px;"><label for="file-{{ $type }}">{{ $label }} (.xlsx ou .csv)</label><input id="file-{{ $type }}" type="file" name="file" accept=".xlsx,.csv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required></div>
    <button type="submit">Ver prévia</button>
    <a class="button secondary" href="{{ route('commerce.imports.template', $type) }}">Baixar modelo</a>
</form>
