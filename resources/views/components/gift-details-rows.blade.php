@props([
    'rows' => [],
])

@if ($rows !== [])
    <dl class="mt-4 divide-y divide-line border-y border-line">
        @foreach ($rows as $row)
            <div class="grid grid-cols-1 gap-1 py-3 text-[15px] sm:grid-cols-[130px_minmax(0,1fr)] sm:gap-4">
                <dt class="text-ink-muted">{{ $row['label'] }}</dt>
                <dd class="min-w-0 break-words text-ink">{{ $row['value'] }}</dd>
            </div>
        @endforeach
    </dl>
@endif
