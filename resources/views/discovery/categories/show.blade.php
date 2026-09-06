@extends('layouts.public')

@section('title', $seoTitle)

@section('page-header')
    <x-listing.page-header :breadcrumbs="$breadcrumbs" :heading="$category->name">
        @if ($category->description)
            <p class="mt-3 max-w-2xl text-[15px] leading-relaxed text-ink-muted">
                {{ $category->description }}
            </p>
        @endif
    </x-listing.page-header>
@endsection

@section('content')
    @php
        $browseLinks = $children->map(fn ($child) => [
            'label' => $child->name,
            'href' => \App\Support\DiscoveryUrl::giftIdeasCategory($child->full_path),
        ])->all();
        $relatedLinks = ($relatedLandingPages ?? collect())->map(fn ($page) => [
            'label' => $page->heading,
            'href' => \App\Support\DiscoveryUrl::seoLandingPage($page->slug),
        ])->all();
    @endphp

    <x-listing.contextual-links :links="$browseLinks" label="Browse" class="pb-6" />

    <x-listing.contextual-links
        :links="$relatedLinks"
        :label="'Related '.\App\Support\Terminology::giftIdeas()"
        class="pb-6"
    />

    <livewire:gift-listing :context="$listingContext" />
@endsection
