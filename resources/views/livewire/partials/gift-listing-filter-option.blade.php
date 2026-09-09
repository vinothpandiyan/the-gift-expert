@php
    $value = $option->slug;
    $inputId = $idPrefix.'-'.$dimension.'-'.str_replace(['/', ' '], '-', $value);
    $countText = $option->count === 1 ? '1 gift idea' : $option->count.' gift ideas';
@endphp

<label
    for="{{ $inputId }}"
    wire:key="{{ $idPrefix }}-{{ $dimension }}-{{ $value }}"
    @class([
        'flex min-h-11 cursor-pointer items-start gap-2 rounded-sm px-1 py-1 text-[14px] text-ink hover:text-plum',
        'pl-7' => $indent,
    ])
>
    <input
        id="{{ $inputId }}"
        type="{{ $isBudget ? 'radio' : 'checkbox' }}"
        @if ($isBudget) name="{{ $idPrefix }}-budget" @endif
        value="{{ $value }}"
        @checked($option->selected)
        @if ($isBudget)
            wire:click="toggleFilter('{{ $dimension }}', @js($value))"
        @else
            wire:change="setFilter('{{ $dimension }}', @js($value), $event.target.checked)"
        @endif
        @class([
            'form-control mt-0.5',
            'form-control-radio' => $isBudget,
            'form-control-checkbox' => ! $isBudget,
        ])
    >
    <span class="flex min-w-0 flex-1 items-start justify-between gap-2" aria-hidden="true">
        <span class="min-w-0 flex-1 leading-snug">{{ $option->label }}</span>
        <span class="filter-option-count shrink-0 leading-snug">({{ $option->count }})</span>
    </span>
    <span class="sr-only">{{ $option->label }}, {{ $countText }}</span>
</label>
