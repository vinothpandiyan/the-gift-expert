@extends('layouts.public')

@section('title', $seoTitle)

@section('page-header')
    <x-listing.page-header :breadcrumbs="$breadcrumbs" :heading="$page->heading">
        @if (filled($page->intro_content))
            <div class="mt-3 max-w-2xl text-[15px] leading-relaxed text-ink-muted">
                {!! nl2br(e($page->intro_content)) !!}
            </div>
        @endif
    </x-listing.page-header>
@endsection

@section('content')
    <livewire:gift-listing :context="$listingContext" />

    @if (filled($page->body_content))
        <section class="mt-12 max-w-3xl space-y-4 text-[15px] leading-relaxed text-ink-muted">
            {!! nl2br(e($page->body_content)) !!}
        </section>
    @endif

    @if (filled($page->faq_content))
        <section class="mt-12 max-w-3xl">
            <h2 class="font-serif text-2xl text-ink">FAQ</h2>
            <div class="mt-3 text-[15px] leading-relaxed text-ink-muted">
                {!! nl2br(e($page->faq_content)) !!}
            </div>
        </section>
    @endif
@endsection
