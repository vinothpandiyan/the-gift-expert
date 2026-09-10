@props([
    'description' => null,
    'canonical' => null,
    'robots' => 'index, follow',
    'prev' => null,
    'next' => null,
    'openGraphTitle' => null,
    'openGraphDescription' => null,
    'openGraphImage' => null,
])

<meta name="robots" content="{{ $robots }}">

@if (filled($description))
    <meta name="description" content="{{ $description }}">
@endif

@if (filled($canonical))
    <link rel="canonical" href="{{ $canonical }}">
@endif

@if (filled($prev))
    <link rel="prev" href="{{ $prev }}">
@endif

@if (filled($next))
    <link rel="next" href="{{ $next }}">
@endif

@if (filled($openGraphTitle))
    <meta property="og:title" content="{{ $openGraphTitle }}">
@endif

@if (filled($openGraphDescription))
    <meta property="og:description" content="{{ $openGraphDescription }}">
@endif

@if (filled($canonical))
    <meta property="og:url" content="{{ $canonical }}">
@endif

@if (filled($openGraphImage))
    <meta property="og:image" content="{{ $openGraphImage }}">
@endif
