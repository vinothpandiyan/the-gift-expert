@props([
    'pages' => [],
    'heading' => 'Related gift ideas',
])

@if ($pages->isNotEmpty())
    <section class="mb-10">
        <h2 class="mb-4 text-lg font-semibold text-stone-900">
            {{ $heading }}
        </h2>
        <ul class="flex flex-wrap gap-2">
            @foreach ($pages as $page)
                <li>
                    <a
                        href="{{ \App\Support\DiscoveryUrl::seoLandingPage($page->slug) }}"
                        class="inline-flex rounded-full border border-stone-300 bg-white px-3 py-1.5 text-sm text-stone-700 hover:border-stone-400 hover:bg-stone-50"
                    >
                        {{ $page->heading }}
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
