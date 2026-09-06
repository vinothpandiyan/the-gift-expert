@props([
    'affiliateLink',
])

@php
    $merchantName = $affiliateLink->merchant?->name ?? 'Merchant';
    $outUrl = \App\Support\DiscoveryUrl::affiliateOut($affiliateLink->uuid);
@endphp

<li class="grid grid-cols-1 items-center gap-4 rounded-[12px] border border-line bg-ivory p-4 sm:grid-cols-[minmax(0,1fr)_auto]">
    <div class="min-w-0">
        <p class="text-[15px] font-semibold text-ink">{{ $merchantName }}</p>
    </div>
    <x-ui.button
        variant="secondary"
        :href="$outUrl"
        target="_blank"
        rel="noopener noreferrer nofollow sponsored"
        class="w-full shrink-0 sm:w-auto"
    >
        View deal
        <span class="sr-only">(opens in a new tab)</span>
        <span aria-hidden="true">↗</span>
    </x-ui.button>
</li>
