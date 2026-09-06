@extends('layouts.public')

@section('title', $seoTitle)

@section('page-header')
    <x-listing.page-header :breadcrumbs="$breadcrumbs" :heading="$heading" :eyebrow="$taxonomyLabel">
        @if (! empty($taxonomy->description))
            <p class="mt-3 max-w-2xl text-[15px] leading-relaxed text-ink-muted">
                {{ $taxonomy->description }}
            </p>
        @endif
    </x-listing.page-header>
@endsection

@section('content')
    @php
        $relatedLinks = ($relatedLandingPages ?? collect())->map(fn ($page) => [
            'label' => $page->heading,
            'href' => \App\Support\DiscoveryUrl::seoLandingPage($page->slug),
        ])->all();
    @endphp

    <x-listing.contextual-links
        :links="$relatedLinks"
        :label="'Related '.\App\Support\Terminology::giftIdeas()"
        class="pb-6"
    />

    <livewire:gift-listing :context="$listingContext" />
@endsection
