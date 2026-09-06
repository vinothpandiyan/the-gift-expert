@props([
    'items' => [],
])

@if (count($items) > 0)
    <nav aria-label="Breadcrumb" class="mb-6 text-sm text-ink-muted">
        <ol class="flex flex-wrap items-center gap-1">
            @foreach ($items as $index => $item)
                <li class="flex items-center gap-1">
                    @if ($index > 0)
                        <span aria-hidden="true" class="text-line">/</span>
                    @endif

                    @if (! empty($item['url']))
                        <a href="{{ $item['url'] }}" class="hover:text-plum hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum">
                            {{ $item['label'] }}
                        </a>
                    @else
                        <span @if ($index === array_key_last($items)) class="text-ink" aria-current="page" @endif>
                            {{ $item['label'] }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
