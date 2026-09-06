@extends('layouts.public')

@section('title', $seoTitle)

@section('content')
    <x-breadcrumbs :items="$breadcrumbs" />

    <header class="mb-8 space-y-3">
        <p class="text-sm font-medium uppercase tracking-wide text-ink-muted">
            {{ $taxonomyLabel }}
        </p>
        <h1 class="font-serif text-4xl tracking-tight text-plum sm:text-5xl">
            {{ $heading }}
        </h1>
        @if (! empty($taxonomy->description))
            <p class="max-w-3xl text-base leading-relaxed text-ink-muted">
                {{ $taxonomy->description }}
            </p>
        @endif
    </header>

    <x-related-seo-landing-pages
        :pages="$relatedLandingPages ?? collect()"
        :heading="'Related '.\App\Support\Terminology::giftIdeas()"
    />

    <livewire:gift-listing :context="$listingContext" />
@endsection
