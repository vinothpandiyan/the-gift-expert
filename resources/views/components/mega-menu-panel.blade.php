@props([
    'menu' => [],
])

@php
    $slug = (string) ($menu['slug'] ?? '');
    $label = (string) ($menu['label'] ?? '');
    $sections = $menu['sections'] ?? [];
    $sectionCount = is_countable($sections) ? count($sections) : 0;
    $gridClass = match (true) {
        $sectionCount <= 1 => 'grid-cols-1',
        $sectionCount === 2 => 'grid-cols-2',
        $sectionCount === 3 => 'grid-cols-3',
        $sectionCount === 4 => 'grid-cols-4',
        default => 'grid-cols-2 lg:grid-cols-5',
    };
    $panelId = 'mega-menu-'.$slug;
@endphp

@if ($slug !== '' && $sectionCount > 0)
    <div class="pointer-events-none absolute inset-x-0 top-full z-40 hidden lg:block">
        <div
            id="{{ $panelId }}"
            x-show="openMenu === @js($slug)"
            x-cloak
            @mouseenter="keepOpen()"
            @mouseleave="scheduleClose()"
            role="region"
            aria-label="{{ $label }}"
            class="pointer-events-auto border-b border-line bg-surface shadow-sm"
        >
            <div class="mx-auto grid max-w-7xl gap-8 px-4 py-8 sm:px-6 {{ $gridClass }}">
                @foreach ($sections as $section)
                    @php
                        $isCta = ($section['appearance'] ?? 'default') === 'cta';
                    @endphp
                    <div @class([
                        'min-w-0',
                        'rounded-md border border-line bg-plum-light p-4' => $isCta,
                    ])>
                        @if (! empty($section['heading']))
                            <p @class([
                                'text-xs font-semibold tracking-wide text-ink-muted',
                                'uppercase' => ! $isCta,
                                'font-serif text-sm text-plum' => $isCta,
                            ])>
                                {{ $section['heading'] }}
                            </p>
                        @endif

                        <ul @class(['mt-3 space-y-2', 'mt-4' => $isCta])>
                            @foreach ($section['links'] ?? [] as $link)
                                @continue(! filled($link['href'] ?? null))
                                <li>
                                    <a
                                        href="{{ $link['href'] }}"
                                        @if (! empty($link['opens_in_new_tab'])) target="_blank" rel="noopener noreferrer" @endif
                                        @click="closeAll()"
                                        @class([
                                            'text-sm text-ink hover:text-plum hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum',
                                            'font-medium text-plum-dark' => $isCta,
                                        ])
                                    >
                                        {{ $link['label'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
