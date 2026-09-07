@props([
    'variant' => 'primary',
    'href' => null,
    'type' => 'button',
])

@php
    $classes = match ($variant) {
        'secondary' => 'border border-plum/30 bg-surface text-plum hover:border-plum hover:bg-plum-light',
        'ghost' => 'bg-transparent text-plum hover:bg-plum-light',
        'coral' => 'bg-coral text-white hover:brightness-95',
        default => 'bg-plum text-white hover:bg-plum-dark',
    };
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        {{ $attributes->merge(['class' => 'inline-flex min-h-11 items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum '.$classes]) }}
    >
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        {{ $attributes->merge(['class' => 'inline-flex min-h-11 items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum '.$classes]) }}
    >
        {{ $slot }}
    </button>
@endif
