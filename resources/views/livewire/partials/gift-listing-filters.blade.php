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

    <fieldset class="space-y-2">
        <legend class="text-sm font-semibold text-ink">{{ $label }}</legend>
        <div class="space-y-1">
            @foreach ($options[$dimension] as $option)
                @php
                    $value = $dimension === 'category'
                        ? (string) $option->full_path
                        : (string) $option->slug;
                    $inputId = $idPrefix.'-'.$dimension.'-'.str_replace('/', '-', $value);
                    $isChecked = in_array($value, $selected[$dimension] ?? [], true);
                @endphp
                <label for="{{ $inputId }}" class="flex min-h-11 cursor-pointer items-start gap-3 rounded-md px-1 py-1 hover:bg-plum-light/60">
                    <input
                        id="{{ $inputId }}"
                        type="checkbox"
                        value="{{ $value }}"
                        @checked($isChecked)
                        wire:click.prevent="toggleFilter('{{ $dimension }}', @js($value))"
                        class="mt-1 size-4 shrink-0 rounded border-line text-plum focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                    >
                    <span class="text-sm text-ink">{{ $option->name }}</span>
                </label>
            @endforeach
        </div>
    </fieldset>
@endforeach
