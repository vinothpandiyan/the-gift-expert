@extends('layouts.public')

@section('title', $seoTitle)

@section('content')
    <x-breadcrumbs :items="$breadcrumbs" />

    <header class="mb-8 space-y-3">
        <h1 class="font-serif text-4xl tracking-tight text-plum sm:text-5xl">
            {{ $page->heading }}
        </h1>
        @if (filled($page->intro_content))
            <div class="max-w-3xl text-base leading-relaxed text-ink-muted">
                {!! nl2br(e($page->intro_content)) !!}
            </div>
        @endif
    </header>

    <livewire:gift-listing :context="$listingContext" />

    @if (filled($page->body_content))
        <section class="mt-12 max-w-3xl space-y-3 text-base leading-relaxed text-ink">
            {!! nl2br(e($page->body_content)) !!}
        </section>
    @endif

    @if (filled($page->faq_content))
        <section class="mt-10 max-w-3xl space-y-3">
            <h2 class="font-serif text-xl text-plum">FAQ</h2>
            <div class="text-base leading-relaxed text-ink">
                {!! nl2br(e($page->faq_content)) !!}
            </div>
        </section>
    @endif
@endsection
