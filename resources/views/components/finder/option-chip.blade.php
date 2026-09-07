@props([
    'selected' => false,
    'disabled' => false,
    'label',
])

<button
    type="button"
    aria-pressed="{{ $selected ? 'true' : 'false' }}"
    @disabled($disabled)
    @if ($disabled)
        aria-disabled="true"
    @endif
    {{ $attributes->class([
        'inline-flex min-h-12 items-center rounded-md border px-4 text-[15px] font-medium transition-colors',
        'border-plum bg-plum text-white' => $selected,
        'border-line bg-surface text-ink-muted opacity-50' => $disabled && ! $selected,
        'border-line bg-surface text-ink hover:border-plum/40 hover:bg-plum-light/50' => ! $selected && ! $disabled,
    ]) }}
>
    {{ $label }}
</button>
