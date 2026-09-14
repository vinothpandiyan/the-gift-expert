@php
    $items ??= [];
    $empty ??= '—';
@endphp

<div class="flex flex-wrap gap-1.5">
    @forelse ($items as $item)
        @php
            $name = is_array($item) ? (string) ($item['name'] ?? '') : (string) $item;
        @endphp
        @if ($name !== '')
            <x-filament::badge color="gray" size="sm">
                {{ $name }}
            </x-filament::badge>
        @endif
    @empty
        <span class="text-sm text-gray-400">{{ $empty }}</span>
    @endforelse
</div>
