@props(['name'])
@php
    // Ícones de traço (24x24), no mesmo estilo do menu da Foxy atual.
    $paths = [
        'menu' => '<path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/>',
        'home' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 11.5 12 4l9 7.5M5.5 9.5V20h5v-5h3v5h5V9.5"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path stroke-linecap="round" d="M3.5 10h17M8 3v4M16 3v4"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path stroke-linecap="round" d="M2.5 20c.6-3.6 3.3-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path stroke-linecap="round" d="M16 4.8a3.3 3.3 0 0 1 0 6.4M18 14.8c1.9.7 3.2 2.4 3.5 5.2"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="14" rx="2"/><path stroke-linecap="round" d="M3 10h18M16 15h2"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path stroke-linecap="round" d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/>',
        'sparkles' => '<path stroke-linejoin="round" d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path stroke-linecap="round" d="M19 15v4M17 17h4"/>',
        'logout' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 16l4-4-4-4M14 12H4"/>',
        'send' => '<path stroke-linejoin="round" d="M4 12 20 4l-6 16-3-7z"/>',
        'copy' => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
    ];
@endphp
<svg viewBox="0 0 24 24" aria-hidden="true" {{ $attributes->merge(['width' => 18, 'height' => 18, 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => 1.6]) }}>{!! $paths[$name] ?? '' !!}</svg>
