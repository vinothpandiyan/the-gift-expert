@props([
    'variant' => 'primary',
    'href' => null,
    'type' => 'button',
])

@php
    $classes = match ($variant) {
        'secondary' => 'border border-line bg-surface text-ink hover:border-plum hover:bg-plum-light',
        'ghost' => 'bg-transparent text-plum hover:underline',
        'coral' => 'bg-coral text-white hover:bg-coral/90',
        default => 'bg-plum text-white hover:bg-plum-dark',
    };
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        {{ $attributes->merge(['class' => 'inline-flex min-h-11 items-center justify-center rounded-md px-4 py-2 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum '.$classes]) }}
    >
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        {{ $attributes->merge(['class' => 'inline-flex min-h-11 items-center justify-center rounded-md px-4 py-2 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum '.$classes]) }}
    >
        {{ $slot }}
    </button>
@endif
