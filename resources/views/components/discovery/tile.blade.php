@props([
    'href',
    'variant' => 'recipient',
])

@php
    $classes = match ($variant) {
        'occasion' => 'group flex h-full min-h-[140px] flex-col justify-between rounded-lg border border-line bg-surface p-5 transition-colors hover:border-plum/40 hover:bg-plum-light/40',
        'budget' => 'flex min-h-24 flex-col justify-center rounded-lg border border-line bg-ivory p-4 transition-colors hover:border-plum/50 hover:bg-plum-light',
        default => 'flex min-h-14 items-center justify-between rounded-lg border border-line bg-ivory px-4 text-[15px] font-semibold transition-colors hover:border-plum/50 hover:bg-plum-light',
    };
@endphp

<a
    href="{{ $href }}"
    {{ $attributes->merge(['class' => $classes.' focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum']) }}
>
    {{ $slot }}
</a>
