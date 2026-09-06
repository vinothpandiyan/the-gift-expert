<footer class="border-t border-line bg-surface">
    <x-ui.container class="py-14 md:py-20">
        <div class="grid gap-12 md:grid-cols-[1.2fr_1fr_1.6fr]">
            <div>
                <x-site-logo />
                <p class="mt-4 max-w-xs text-[14px] leading-relaxed text-ink-muted">
                    We help you work out what to give — then send you to the merchant with the best listing.
                </p>
            </div>

            <div>
                <p class="mb-4 text-xs font-semibold uppercase tracking-[0.14em] text-ink-muted">Gift discovery</p>
                <nav aria-label="Footer">
                    <ul class="space-y-2.5">
                        <li>
                            <a href="{{ \App\Support\DiscoveryUrl::giftIdeas() }}" class="text-[14px] hover:text-plum hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum">
                                {{ \App\Support\Terminology::giftIdeas() }}
                            </a>
                        </li>
                        <li>
                            <a href="{{ \App\Support\DiscoveryUrl::finder() }}" class="text-[14px] hover:text-plum hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum">
                                Find a Gift
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>

            <div>
                <p class="text-[13px] leading-relaxed text-ink-muted">
                    When you buy through some links on {{ config('app.name') }}, we may earn a commission at no extra cost to you. We don't sell any of the products listed — prices and availability are set by the merchant.
                </p>
            </div>
        </div>

        <div class="mt-12 border-t border-line pt-6">
            <p class="text-[13px] text-ink-muted">
                © {{ now()->year }} {{ config('app.name') }}. All rights reserved.
            </p>
        </div>
    </x-ui.container>
</footer>
