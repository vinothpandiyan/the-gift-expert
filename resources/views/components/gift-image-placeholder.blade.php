@props([
    'label' => 'Image coming soon',
])

<div {{ $attributes->merge(['class' => 'flex h-full w-full flex-col items-center justify-center gap-2 bg-plum-light px-4 text-center']) }}>
    <svg class="h-10 w-10 text-ink-muted/40" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
    </svg>
    <p class="text-sm text-ink-muted">{{ $label }}</p>
</div>
