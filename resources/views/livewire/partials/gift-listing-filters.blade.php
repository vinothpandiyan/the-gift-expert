@php
    $groups = [
        'occasion' => 'Occasion',
        'relationship' => 'Relationship',
        'recipient' => 'Recipient',
        'budget' => 'Budget',
        'interest' => 'Interest',
        'profession' => 'Profession',
        'gift_type' => 'Gift Type',
        'category' => 'Category',
    ];
@endphp

@foreach ($groups as $dimension => $label)
    @continue(! isset($options[$dimension]) || $options[$dimension]->isEmpty())

    <div class="border-b border-line py-4 last:border-b-0" x-data="{ open: true }">
        <button
            type="button"
            @click="open = ! open"
            :aria-expanded="open"
            aria-expanded="true"
            class="flex min-h-11 w-full items-center justify-between text-left text-[14px] font-semibold text-ink"
        >
            {{ $label }}
            <span class="text-ink-muted" aria-hidden="true">
                <svg x-show="open" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="size-4">
                    <path stroke-linecap="round" d="M5 12h14" />
                </svg>
                <svg x-show="! open" x-cloak xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="size-4">
                    <path stroke-linecap="round" d="M12 5v14M5 12h14" />
                </svg>
            </span>
        </button>

        <div x-show="open" class="mt-1.5 flex flex-col">
            @foreach ($options[$dimension] as $option)
                @php
                    $value = $dimension === 'category'
                        ? (string) $option->full_path
                        : (string) $option->slug;
                    $inputId = $idPrefix.'-'.$dimension.'-'.str_replace('/', '-', $value);
                    $isChecked = in_array($value, $selected[$dimension] ?? [], true);
                    $isBudget = $dimension === 'budget';
                @endphp
                <label for="{{ $inputId }}" class="flex min-h-11 cursor-pointer items-center gap-3 rounded-[8px] px-1 text-[14px] text-ink hover:text-plum">
                    <input
                        id="{{ $inputId }}"
                        type="{{ $isBudget ? 'radio' : 'checkbox' }}"
                        @if ($isBudget) name="{{ $idPrefix }}-budget" @endif
                        value="{{ $value }}"
                        @checked($isChecked)
                        wire:click.prevent="toggleFilter('{{ $dimension }}', @js($value))"
                        class="size-[18px] shrink-0 rounded border-line accent-plum focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                    >
                    <span>{{ $option->name }}</span>
                </label>
            @endforeach
        </div>
    </div>
@endforeach
