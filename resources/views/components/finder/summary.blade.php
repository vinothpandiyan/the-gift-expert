@props([
    'items',
])

@if ($items !== [])
    <dl class="mt-5 grid grid-cols-2 gap-3 border-t border-line pt-5 md:grid-cols-4">
        @foreach ($items as $item)
            <div>
                <dt class="text-[12px] tracking-wide text-ink-muted uppercase">{{ $item['label'] }}</dt>
                <dd class="mt-1 text-[15px] font-semibold text-ink">{{ $item['value'] }}</dd>
            </div>
        @endforeach
    </dl>
@endif
