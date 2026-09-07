@foreach ($options as $dimension => $dimensionOptions)
    @continue($dimensionOptions->isEmpty())

    @php
        $label = \App\DiscoveryListing\DiscoveryListingContext::dimensionLabel($dimension);
        $headingId = $idPrefix.'-'.$dimension.'-heading';
        $panelId = $idPrefix.'-'.$dimension.'-panel';
        $isBudget = $dimension === 'budget';
        $rows = $dimension === 'category'
            ? \App\DiscoveryListing\DiscoveryFilterOptionTree::nest($dimensionOptions)
            : $dimensionOptions->map(fn ($option) => ['option' => $option, 'children' => []])->all();
    @endphp

    <fieldset class="border-b border-line py-4 last:border-b-0" x-data="{ open: true }">
        <legend class="w-full">
            <button
                type="button"
                id="{{ $headingId }}"
                @click="open = ! open"
                :aria-expanded="open"
                aria-expanded="true"
                aria-controls="{{ $panelId }}"
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
        </legend>

        <div id="{{ $panelId }}" x-show="open" role="group" aria-labelledby="{{ $headingId }}" class="mt-1.5 flex flex-col">
            @foreach ($rows as $row)
                @include('livewire.partials.gift-listing-filter-option', [
                    'dimension' => $dimension,
                    'option' => $row['option'],
                    'idPrefix' => $idPrefix,
                    'isBudget' => $isBudget,
                    'indent' => false,
                ])

                @foreach ($row['children'] as $child)
                    @include('livewire.partials.gift-listing-filter-option', [
                        'dimension' => $dimension,
                        'option' => $child,
                        'idPrefix' => $idPrefix,
                        'isBudget' => $isBudget,
                        'indent' => true,
                    ])
                @endforeach
            @endforeach
        </div>
    </fieldset>
@endforeach
