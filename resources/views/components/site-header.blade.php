@php
    $finderUrl = \App\Support\DiscoveryUrl::finder();
@endphp

<header class="relative z-40 border-b border-line bg-surface">
    <div
        x-data="primaryNav"
        x-effect="document.body.classList.toggle('overflow-hidden', mobileOpen)"
        @keydown.escape.window="closeAll()"
        @click.outside="closeDesktop()"
        class="relative"
    >
        <div class="relative z-50 mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
            <a href="{{ url('/') }}" class="shrink-0 font-serif text-xl tracking-tight text-plum focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum">
                {{ config('app.name') }}
            </a>

            <div class="hidden min-w-0 items-center gap-6 lg:flex">
                <nav aria-label="Primary" class="flex flex-wrap items-center gap-1">
                    @foreach ($navigation as $menu)
                        @php
                            $itemType = $menu['item_type'] ?? '';
                            $slug = (string) ($menu['slug'] ?? '');
                            $label = (string) ($menu['label'] ?? '');
                        @endphp

                        @if ($itemType === 'mega' && $slug !== '')
                            <button
                                type="button"
                                x-ref="trigger-{{ $slug }}"
                                @mouseenter="open(@js($slug))"
                                @mouseleave="scheduleClose()"
                                @click="toggle(@js($slug))"
                                @keydown.down.prevent="open(@js($slug)); focusFirstLink(@js($slug))"
                                :aria-expanded="openMenu === @js($slug)"
                                aria-expanded="false"
                                aria-haspopup="true"
                                aria-controls="mega-menu-{{ $slug }}"
                                class="min-h-11 rounded-md px-3 py-2 text-sm text-ink-muted hover:bg-plum-light hover:text-plum-dark focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                            >
                                {{ $label }}
                            </button>
                        @elseif ($itemType === 'link' && filled($menu['href'] ?? null))
                            <a
                                href="{{ $menu['href'] }}"
                                @if (! empty($menu['opens_in_new_tab'])) target="_blank" rel="noopener noreferrer" @endif
                                @click="closeAll()"
                                class="inline-flex min-h-11 items-center rounded-md px-3 py-2 text-sm text-ink-muted hover:bg-plum-light hover:text-plum-dark hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                            >
                                {{ $label }}
                            </a>
                        @endif
                    @endforeach
                </nav>

                <a
                    href="{{ $finderUrl }}"
                    @click="closeAll()"
                    class="inline-flex min-h-11 shrink-0 items-center rounded-md bg-plum px-4 py-2 text-sm font-medium text-white hover:bg-plum-dark focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                >
                    Find a Gift
                </a>
            </div>

            <div class="flex items-center gap-3 lg:hidden">
                <a
                    href="{{ $finderUrl }}"
                    class="inline-flex min-h-11 items-center text-sm font-medium text-plum hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                >
                    Find a Gift
                </a>
                <button
                    type="button"
                    x-ref="hamburger"
                    @click="mobileOpen = true"
                    :aria-expanded="mobileOpen"
                    aria-expanded="false"
                    aria-controls="mobile-primary-nav"
                    aria-label="Open menu"
                    class="inline-flex size-11 items-center justify-center rounded-md text-ink hover:bg-plum-light focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="size-6" aria-hidden="true">
                        <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
                    </svg>
                </button>
            </div>
        </div>

        @foreach ($navigation as $menu)
            @if (($menu['item_type'] ?? '') === 'mega')
                <x-mega-menu-panel :menu="$menu" />
            @endif
        @endforeach

        <div class="lg:hidden">
            <div
                x-show="mobileOpen"
                x-cloak
                x-transition.opacity.duration.150ms
                class="fixed inset-0 z-40 bg-plum-dark/40"
                @click="closeMobile()"
            ></div>

            <div
                id="mobile-primary-nav"
                x-show="mobileOpen"
                x-cloak
                role="dialog"
                aria-modal="true"
                aria-label="Primary"
                class="fixed inset-y-0 right-0 z-50 flex w-full max-w-sm flex-col overflow-y-auto overflow-x-hidden border-l border-line bg-surface shadow-lg"
            >
                <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-4">
                    <a
                        href="{{ $finderUrl }}"
                        @click="closeAll()"
                        class="inline-flex min-h-11 items-center text-sm font-medium text-plum hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                    >
                        Find a Gift
                    </a>
                    <button
                        type="button"
                        @click="closeMobile()"
                        aria-label="Close menu"
                        class="inline-flex size-11 items-center justify-center rounded-md text-ink hover:bg-plum-light focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="size-6" aria-hidden="true">
                            <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                        </svg>
                    </button>
                </div>

                <nav aria-label="Mobile primary" class="flex flex-1 flex-col gap-1 px-2 py-3">
                    @foreach ($navigation as $menu)
                        @php
                            $itemType = $menu['item_type'] ?? '';
                            $slug = (string) ($menu['slug'] ?? '');
                            $label = (string) ($menu['label'] ?? '');
                            $accordionId = 'mobile-menu-'.$slug;
                        @endphp

                        @if ($itemType === 'mega' && $slug !== '')
                            <div class="border-b border-line">
                                <button
                                    type="button"
                                    @click="toggleMobileAccordion(@js($slug))"
                                    :aria-expanded="mobileAccordion === @js($slug)"
                                    aria-expanded="false"
                                    aria-controls="{{ $accordionId }}"
                                    class="flex min-h-11 w-full items-center justify-between px-2 py-3 text-left text-sm font-medium text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                                >
                                    <span>{{ $label }}</span>
                                    <span class="text-ink-muted" aria-hidden="true">+</span>
                                </button>
                                <div
                                    id="{{ $accordionId }}"
                                    x-show="mobileAccordion === @js($slug)"
                                    x-cloak
                                    class="space-y-4 px-2 pb-4"
                                >
                                    @foreach ($menu['sections'] ?? [] as $section)
                                        <div>
                                            @if (! empty($section['heading']))
                                                <p class="text-xs font-semibold tracking-wide text-ink-muted">
                                                    {{ $section['heading'] }}
                                                </p>
                                            @endif
                                            <ul class="mt-2 space-y-2">
                                                @foreach ($section['links'] ?? [] as $link)
                                                    @continue(! filled($link['href'] ?? null))
                                                    <li>
                                                        <a
                                                            href="{{ $link['href'] }}"
                                                            @if (! empty($link['opens_in_new_tab'])) target="_blank" rel="noopener noreferrer" @endif
                                                            @click="closeAll()"
                                                            class="inline-flex min-h-11 items-center text-sm text-ink hover:text-plum hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
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
                        @elseif ($itemType === 'link' && filled($menu['href'] ?? null))
                            <a
                                href="{{ $menu['href'] }}"
                                @if (! empty($menu['opens_in_new_tab'])) target="_blank" rel="noopener noreferrer" @endif
                                @click="closeAll()"
                                class="inline-flex min-h-11 items-center px-2 py-3 text-sm font-medium text-ink hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                            >
                                {{ $label }}
                            </a>
                        @endif
                    @endforeach
                </nav>
            </div>
        </div>
    </div>
</header>
