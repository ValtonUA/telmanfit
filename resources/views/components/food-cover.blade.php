@props(['src' => null, 'label', 'variant' => 'thumb'])
<div {{ $attributes->class(['food-cover', 'food-cover-catalog' => $variant === 'catalog', 'food-cover-empty' => ! $src]) }} x-data="{ broken: false }" :class="{ 'food-cover-empty': {{ $src ? 'false' : 'true' }} || broken }" x-init="$nextTick(() => { const img = $el.querySelector('img'); if (img?.complete && !img.naturalWidth) broken = true })">
    @if ($src)<img src="{{ $src }}" alt="" width="160" height="120" loading="lazy" decoding="async" referrerpolicy="no-referrer" sizes="{{ $variant === 'catalog' ? '(min-width:1280px) 16vw, 40vw' : '72px' }}" x-show="!broken" x-on:error="broken = true">@endif
    <span class="food-cover-placeholder" @if($src) x-show="broken" x-cloak @endif aria-label="Без фото: {{ $label }}">Без фото</span>
</div>
