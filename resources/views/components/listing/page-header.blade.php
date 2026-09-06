@props([
    'breadcrumbs' => [],
    'heading',
    'eyebrow' => null,
])

<div class="border-b border-line bg-surface">
    <x-ui.container class="py-6 md:py-10">
        <x-breadcrumbs :items="$breadcrumbs" />
        @if (filled($eyebrow))
            <p class="mt-3 text-[12px] font-semibold uppercase tracking-[0.12em] text-ink-muted">
                {{ $eyebrow }}
            </p>
        @endif
        <h1 @class([
            'font-serif text-[30px] leading-[1.12] tracking-tight text-ink md:text-[44px]',
            'mt-2' => filled($eyebrow),
            'mt-3' => ! filled($eyebrow),
        ])>
            {{ $heading }}
        </h1>
        {{ $slot }}
    </x-ui.container>
</div>
