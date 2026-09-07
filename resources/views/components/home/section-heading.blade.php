@props([
    'eyebrow' => null,
    'heading',
    'description' => null,
])

<div @class(['mb-8 md:mb-10'])>
    @if (filled($eyebrow))
        <p class="text-[12px] font-semibold uppercase tracking-[0.12em] text-ink-muted">{{ $eyebrow }}</p>
    @endif
    <div class="mt-2 flex flex-wrap items-end justify-between gap-3">
        <h2 class="font-serif text-[28px] leading-tight tracking-tight text-ink md:text-[36px]">{{ $heading }}</h2>
        @if ($slot->isNotEmpty())
            <div class="shrink-0">{{ $slot }}</div>
        @endif
    </div>
    @if (filled($description))
        <p class="mt-3 max-w-2xl text-[15px] leading-relaxed text-ink-muted">{{ $description }}</p>
    @endif
</div>
