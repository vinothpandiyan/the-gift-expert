@props([
    'href',
])

<a
    href="{{ $href }}"
    {{ $attributes->merge(['class' => 'inline-flex min-h-12 items-center gap-2 rounded-md border border-line bg-surface px-4 text-sm font-medium transition-colors hover:border-plum/50 hover:bg-plum-light focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum']) }}
>
    {{ $slot }}
</a>
