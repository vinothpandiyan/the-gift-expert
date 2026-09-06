@extends('layouts.public')

@section('title', $seoTitle)

@section('content')
    <x-breadcrumbs :items="$breadcrumbs" />

    <header class="mb-8 space-y-3">
        <p class="text-sm font-medium uppercase tracking-wide text-ink-muted">
            {{ \App\Support\Terminology::giftIdeas() }}
        </p>
        <h1 class="font-serif text-4xl tracking-tight text-plum sm:text-5xl">
            {{ $category->name }}
        </h1>
        @if ($category->description)
            <p class="max-w-3xl text-base leading-relaxed text-ink-muted">
                {{ $category->description }}
            </p>
        @endif
    </header>

    @if ($children->isNotEmpty())
        <section class="mb-8">
            <h2 class="mb-4 font-serif text-xl text-plum">Browse</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($children as $child)
                    <li>
                        <a
                            href="{{ \App\Support\DiscoveryUrl::giftIdeasCategory($child->full_path) }}"
                            class="inline-flex min-h-11 items-center rounded-full border border-line bg-surface px-4 py-2 text-sm text-ink hover:border-plum hover:bg-plum-light focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                        >
                            {{ $child->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <x-related-seo-landing-pages
        :pages="$relatedLandingPages ?? collect()"
        :heading="'Related '.\App\Support\Terminology::giftIdeas()"
    />

    <livewire:gift-listing :context="$listingContext" />
@endsection
