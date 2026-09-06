<footer class="border-t border-line bg-surface">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-8 text-sm text-ink-muted sm:px-6">
        <span class="font-serif text-base text-plum">{{ config('app.name') }}</span>
        <nav aria-label="Footer" class="flex items-center gap-4">
            <a href="{{ \App\Support\DiscoveryUrl::finder() }}" class="inline-flex min-h-11 items-center hover:text-plum hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum">
                Find a Gift
            </a>
            <a href="{{ \App\Support\DiscoveryUrl::giftIdeas() }}" class="inline-flex min-h-11 items-center hover:text-plum hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum">
                {{ \App\Support\Terminology::giftIdeas() }}
            </a>
        </nav>
    </div>
</footer>
