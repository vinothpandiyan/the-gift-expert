<footer class="border-t border-stone-200 bg-white">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-6 text-sm text-stone-500 sm:px-6">
        <span>{{ config('app.name') }}</span>
        <nav aria-label="Footer" class="flex items-center gap-4">
            <a href="{{ \App\Support\DiscoveryUrl::finder() }}" class="hover:text-stone-800 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-stone-400">
                Find a Gift
            </a>
            <a href="{{ \App\Support\DiscoveryUrl::giftIdeas() }}" class="hover:text-stone-800 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-stone-400">
                {{ \App\Support\Terminology::giftIdeas() }}
            </a>
        </nav>
    </div>
</footer>
