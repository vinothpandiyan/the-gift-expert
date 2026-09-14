@php
    $heading ??= 'Factors';
    $factors ??= [];
@endphp

<section class="min-w-0 overflow-x-auto rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $heading }}</h3>
    @if ($factors === [])
        <p class="mt-3 text-sm text-gray-400">No factor data recorded.</p>
    @else
        <table class="mt-3 min-w-full border-collapse overflow-hidden rounded-lg text-left text-sm">
            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800/80">
                <tr>
                    <th class="border border-gray-200 px-3 py-2 font-medium dark:border-gray-700">Factor</th>
                    <th class="border border-gray-200 px-3 py-2 text-right font-medium dark:border-gray-700">Score</th>
                    <th class="border border-gray-200 px-3 py-2 text-right font-medium dark:border-gray-700">Max</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($factors as $factor)
                    <tr>
                        <td class="border border-gray-200 px-3 py-2 dark:border-gray-700">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $factor['label'] }}</span>
                            @if (filled($factor['detail'] ?? null))
                                <span class="mt-0.5 block text-xs text-gray-500">{{ $factor['detail'] }}</span>
                            @endif
                        </td>
                        <td class="border border-gray-200 px-3 py-2 text-right tabular-nums font-semibold dark:border-gray-700">{{ $factor['points'] ?? '—' }}</td>
                        <td class="border border-gray-200 px-3 py-2 text-right tabular-nums text-gray-600 dark:border-gray-700 dark:text-gray-300">{{ $factor['max'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
