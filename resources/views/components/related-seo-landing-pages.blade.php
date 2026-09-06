@props([
    'pages' => [],
    'heading' => 'Related gift ideas',
])

@if ($pages->isNotEmpty())
    <section class="mb-8">
        <h2 class="mb-4 font-serif text-xl text-plum">
            {{ $heading }}
        </h2>
        <ul class="flex flex-wrap gap-2">
            @foreach ($pages as $page)
                <li>
                    <a
                        href="{{ \App\Support\DiscoveryUrl::seoLandingPage($page->slug) }}"
                        class="inline-flex min-h-11 items-center rounded-full border border-line bg-surface px-4 py-2 text-sm text-ink hover:border-plum hover:bg-plum-light focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                    >
                        {{ $page->heading }}
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
