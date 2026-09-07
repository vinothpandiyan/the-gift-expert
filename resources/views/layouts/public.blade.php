<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $title ?? config('app.name'))</title>
    <x-seo-meta
        :description="$seoDescription ?? null"
        :canonical="$seoCanonical ?? null"
        :robots="$seoRobots ?? 'index, follow'"
        :prev="$seoPrev ?? null"
        :next="$seoNext ?? null"
    />
    @stack('head')
    @fonts
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <style>
            @import url('https://fonts.bunny.net/css?family=dm-sans:400,500,600,700|dm-serif-display:400');
            body { font-family: 'DM Sans', ui-sans-serif, system-ui, sans-serif; margin: 0; background: #FCFAF7; color: #262326; }
            a { color: inherit; }
        </style>
    @endif
</head>
<body class="min-h-screen bg-ivory font-sans text-ink antialiased">
    <a
        href="#content"
        class="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-4 focus:z-100 focus:rounded-md focus:bg-plum focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white"
    >
        Skip to content
    </a>

    <x-site-header />

    <main id="content">
        @hasSection('page-header')
            @yield('page-header')
        @endif

        @hasSection('content-uncontained')
            @yield('content-uncontained')
        @else
            <div @class([
                'mx-auto w-full max-w-page px-5 md:px-8',
                'py-8 md:py-10' => ! View::hasSection('page-header'),
                'pt-6 pb-16 md:pt-8' => View::hasSection('page-header'),
            ])>
                @yield('content')
            </div>
        @endif
    </main>

    <x-site-footer />
</body>
</html>
