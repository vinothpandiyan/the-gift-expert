@props([
    'selected' => false,
    'label',
    'note' => null,
    'role' => 'radio',
])

<button
    type="button"
    role="{{ $role }}"
    @if ($role === 'radio')
        aria-checked="{{ $selected ? 'true' : 'false' }}"
    @else
        aria-pressed="{{ $selected ? 'true' : 'false' }}"
    @endif
    {{ $attributes->class([
        'relative flex min-h-16 w-full flex-col justify-center rounded-[12px] border p-4 text-left transition-colors',
        'border-plum bg-plum-light ring-1 ring-plum' => $selected,
        'border-line bg-surface hover:border-plum/40 hover:bg-plum-light/40' => ! $selected,
    ]) }}
>
    @if ($selected)
        <span class="absolute top-3 right-3 flex h-5 w-5 items-center justify-center rounded-full bg-plum text-white" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-3 w-3">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
        </span>
    @endif
    <span class="pr-6 text-[15px] font-semibold text-ink">{{ $label }}</span>
    @if (filled($note))
        <span class="mt-1 text-[13px] text-ink-muted">{{ $note }}</span>
    @endif
</button>
