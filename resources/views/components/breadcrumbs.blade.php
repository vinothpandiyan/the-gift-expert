@props([
    'items' => [],
])

@if (count($items) > 0)
    <nav aria-label="Breadcrumb" class="mb-6 text-sm text-stone-500">
        <ol class="flex flex-wrap items-center gap-1">
            @foreach ($items as $index => $item)
                <li class="flex items-center gap-1">
                    @if ($index > 0)
                        <span aria-hidden="true" class="text-stone-300">/</span>
                    @endif

                    @if (! empty($item['url']))
                        <a href="{{ $item['url'] }}" class="hover:text-stone-800 hover:underline">
                            {{ $item['label'] }}
                        </a>
                    @else
                        <span @if ($index === array_key_last($items)) class="text-stone-700" aria-current="page" @endif>
                            {{ $item['label'] }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
