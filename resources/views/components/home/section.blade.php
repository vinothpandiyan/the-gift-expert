@props([
    'tone' => 'default',
])

@php
    $toneClass = match ($tone) {
        'surface' => 'bg-surface',
        'plum' => 'bg-plum text-white',
        default => 'bg-ivory',
    };
@endphp

<section {{ $attributes->merge(['class' => 'py-12 md:py-20 '.$toneClass]) }}>
    <x-ui.container>
        {{ $slot }}
    </x-ui.container>
</section>
