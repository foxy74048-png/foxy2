@props(['href' => '#', 'active' => false, 'icon' => null])
<a href="{{ $href }}" @class(['active' => $active]) @if ($active) aria-current="page" @endif {{ $attributes }}>
    @if ($icon)<x-core::icon :name="$icon" />@endif
    <span>{{ $slot }}</span>
</a>
