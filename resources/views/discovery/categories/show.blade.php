@extends('layouts.public')

@section('title', $seoTitle)

@section('content')
    <x-breadcrumbs :items="$breadcrumbs" />

    <header class="mb-8 space-y-3">
        <p class="text-sm font-medium uppercase tracking-wide text-stone-500">
            {{ \App\Support\Terminology::giftIdeas() }}
        </p>
        <h1 class="text-3xl font-semibold tracking-tight text-stone-900 sm:text-4xl">
            {{ $category->name }}
        </h1>
        @if ($category->description)
            <p class="max-w-3xl text-base leading-relaxed text-stone-600">
                {{ $category->description }}
            </p>
        @endif
    </header>

    @if ($children->isNotEmpty())
        <section class="mb-10">
            <h2 class="mb-4 text-lg font-semibold text-stone-900">Browse</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($children as $child)
                    <li>
                        <a
                            href="{{ \App\Support\DiscoveryUrl::giftIdeasCategory($child->full_path) }}"
                            class="inline-flex rounded-full border border-stone-300 bg-white px-3 py-1.5 text-sm text-stone-700 hover:border-stone-400 hover:bg-stone-50"
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

    <section>
        <h2 class="mb-4 text-lg font-semibold text-stone-900">
            {{ \App\Support\Terminology::gifts() }}
        </h2>

        @if ($products->isEmpty())
            <p class="text-stone-500">No {{ strtolower(\App\Support\Terminology::gifts()) }} found in this category yet.</p>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($products as $product)
                    <x-gift-card :product="$product" :context="$giftBrowseContext" />
                @endforeach
            </div>

            <div class="mt-8">
                {{ $products->links() }}
            </div>
        @endif
    </section>
@endsection
