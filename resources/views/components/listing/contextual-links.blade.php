@props([
    'label' => 'Popular in this section',
    'links' => [],
])

@if (count($links) > 0)
    <nav aria-label="{{ $label }}" {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-x-5 gap-y-2 md:gap-x-7']) }}>
        <span class="text-[12px] font-semibold uppercase tracking-[0.12em] text-ink-muted">{{ $label }}</span>
        @foreach ($links as $link)
            @continue(! filled($link['href'] ?? null) || ! filled($link['label'] ?? null))
            <a
                href="{{ $link['href'] }}"
                class="text-[14px] font-medium text-plum underline decoration-plum/25 underline-offset-4 hover:decoration-plum focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
            >
                {{ $link['label'] }}
            </a>
        @endforeach
    </nav>
@endif
