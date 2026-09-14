@php
    $ids = array_values($ids ?? []);
    $limit = $limit ?? 6;
    $preview = array_slice($ids, 0, $limit);
    $rest = array_slice($ids, $limit);
@endphp

@if ($ids === [])
    <span class="text-sm text-gray-400">None</span>
@else
    <div class="flex flex-wrap gap-1.5">
        @foreach ($preview as $id)
            <a
                href="{{ \App\Filament\Resources\Gifts\GiftResource::getUrl('edit', ['record' => $id]) }}"
                class="inline-flex rounded-full bg-gray-50 px-2.5 py-1 text-xs font-medium text-primary-700 ring-1 ring-inset ring-gray-500/20 hover:bg-primary-50 dark:bg-gray-800 dark:text-primary-300"
            >
                Gift {{ $id }}
            </a>
        @endforeach
    </div>
    @if ($rest !== [])
        <details class="mt-2">
            <summary class="cursor-pointer text-xs font-medium text-primary-700 dark:text-primary-300">View {{ count($rest) }} more</summary>
            <div class="mt-2 flex flex-wrap gap-1.5">
                @foreach ($rest as $id)
                    <a
                        href="{{ \App\Filament\Resources\Gifts\GiftResource::getUrl('edit', ['record' => $id]) }}"
                        class="inline-flex rounded-full bg-gray-50 px-2.5 py-1 text-xs font-medium text-primary-700 ring-1 ring-inset ring-gray-500/20 hover:bg-primary-50 dark:bg-gray-800 dark:text-primary-300"
                    >
                        Gift {{ $id }}
                    </a>
                @endforeach
            </div>
        </details>
    @endif
@endif
