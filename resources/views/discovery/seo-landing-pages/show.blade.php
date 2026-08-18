@extends('layouts.public')

@section('title', $seoTitle)

@section('content')
    <x-breadcrumbs :items="$breadcrumbs" />

    <header class="mb-8 space-y-3">
        <h1 class="text-3xl font-semibold tracking-tight text-stone-900 sm:text-4xl">
            {{ $page->heading }}
        </h1>
        @if (filled($page->intro_content))
            <div class="max-w-3xl text-base leading-relaxed text-stone-600">
                {!! nl2br(e($page->intro_content)) !!}
            </div>
        @endif
    </header>

    <section>
        <h2 class="mb-4 text-lg font-semibold text-stone-900">
            {{ \App\Support\Terminology::gifts() }}
        </h2>

        @if ($products->isEmpty())
            <p class="text-stone-500">No {{ strtolower(\App\Support\Terminology::gifts()) }} found yet.</p>
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

    @if (filled($page->body_content))
        <section class="mt-10 max-w-3xl space-y-3 text-base leading-relaxed text-stone-700">
            {!! nl2br(e($page->body_content)) !!}
        </section>
    @endif

    @if (filled($page->faq_content))
        <section class="mt-10 max-w-3xl space-y-3">
            <h2 class="text-lg font-semibold text-stone-900">FAQ</h2>
            <div class="text-base leading-relaxed text-stone-700">
                {!! nl2br(e($page->faq_content)) !!}
            </div>
        </section>
    @endif
@endsection
