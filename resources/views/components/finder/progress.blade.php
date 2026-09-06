@props([
    'step',
    'total' => 5,
    'label',
])

@php
    $step = (int) $step;
    $total = (int) $total;
@endphp

<div>
    <div class="flex items-center justify-between text-[13px]">
        <span class="font-semibold text-plum">
            Step {{ $step }} of {{ $total }}
        </span>
        <span class="text-ink-muted">{{ $label }}</span>
    </div>
    <div
        class="mt-2 flex gap-1.5"
        role="progressbar"
        aria-label="Gift Finder progress"
        aria-valuenow="{{ $step }}"
        aria-valuemin="1"
        aria-valuemax="{{ $total }}"
        aria-valuetext="Step {{ $step }} of {{ $total }}: {{ $label }}"
    >
        @for ($i = 1; $i <= $total; $i++)
            <span @class([
                'h-1.5 flex-1 rounded-full',
                'bg-plum' => $i <= $step,
                'bg-line' => $i > $step,
            ])></span>
        @endfor
    </div>
</div>
