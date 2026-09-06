@props([
    'href',
])

<a
    href="{{ $href }}"
    {{ $attributes->merge(['class' => 'inline-flex min-h-9 items-center rounded-md border border-line bg-surface px-3 py-1.5 text-[13px] text-ink hover:border-plum hover:bg-plum-light focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum']) }}
>
    {{ $slot }}
</a>
