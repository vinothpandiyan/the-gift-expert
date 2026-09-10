@props([
    'images',
    'productName',
])

@php
    $images = collect($images)->values();
    $hasGallery = $images->count() > 1;
    $imageWidth = (int) config('media.product_images.canonical_width');
    $imageHeight = (int) config('media.product_images.canonical_height');
@endphp

<div
    class="mx-auto w-full max-w-[640px]"
    x-data="productGallery"
    @keydown.escape.window="closeLightbox"
    @keydown.tab.window="trapLightbox($event)"
>
    <div class="rounded-xl border border-line bg-surface p-3">
        <div class="aspect-square overflow-hidden rounded-md bg-surface-sunken p-3">
            @if ($images->isEmpty())
                <x-gift-image-placeholder class="h-full bg-transparent" />
            @else
                <button
                    type="button"
                    class="relative block h-full w-full cursor-zoom-in rounded-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                    @click="openLightbox"
                    aria-label="Open larger image of {{ $productName }}"
                >
                    @foreach ($images as $index => $image)
                        <img
                            src="{{ $image->url() }}"
                            alt="{{ $image->alt_text ?: $productName }}"
                            @class([
                                'h-full w-full object-contain',
                                'absolute inset-0' => $hasGallery,
                            ])
                            width="{{ $imageWidth }}"
                            height="{{ $imageHeight }}"
                            @if ($hasGallery) x-show="active === {{ $index }}" @endif
                            @if ($index === 0)
                                fetchpriority="high"
                            @else
                                loading="lazy"
                                x-cloak
                            @endif
                        >
                    @endforeach
                </button>
            @endif
        </div>
    </div>

    @if ($hasGallery)
        <div class="mt-3 flex flex-wrap gap-3">
            @foreach ($images as $index => $image)
                <button
                    type="button"
                    @click="active = {{ $index }}"
                    :aria-current="active === {{ $index }} ? 'true' : 'false'"
                    aria-label="View image {{ $index + 1 }}"
                    class="w-20 overflow-hidden rounded-md border p-1 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                    :class="active === {{ $index }} ? 'border-plum' : 'border-line'"
                >
                    <img
                        src="{{ $image->url() }}"
                        alt=""
                        class="aspect-square w-full object-contain"
                        width="{{ $imageWidth }}"
                        height="{{ $imageHeight }}"
                        loading="lazy"
                    >
                </button>
            @endforeach
        </div>
    @endif

    @if ($images->isNotEmpty())
        <div
            x-show="lightboxOpen"
            x-cloak
            class="fixed inset-0 z-[80] flex items-center justify-center bg-ink/85 p-3 sm:p-6"
            role="presentation"
            @click.self="closeLightbox"
        >
            <div
                x-ref="lightboxPanel"
                role="dialog"
                aria-modal="true"
                aria-labelledby="product-image-lightbox-title"
                tabindex="-1"
                class="relative flex h-full max-h-[min(92vh,1000px)] w-full max-w-6xl flex-col rounded-xl bg-surface p-3 shadow-2xl sm:p-5"
            >
                <div class="flex items-center justify-between gap-4 pb-3">
                    <h2 id="product-image-lightbox-title" class="truncate text-sm font-semibold text-ink">
                        Larger image of {{ $productName }}
                    </h2>
                    <button
                        type="button"
                        x-ref="lightboxClose"
                        @click="closeLightbox"
                        aria-label="Close image viewer"
                        class="inline-flex size-11 shrink-0 items-center justify-center rounded-md border border-line bg-surface text-2xl text-ink hover:border-plum focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum"
                    >
                        <span aria-hidden="true">×</span>
                    </button>
                </div>

                <div class="relative min-h-0 flex-1 overflow-hidden rounded-md bg-surface-sunken">
                    @foreach ($images as $index => $image)
                        <img
                            src="{{ $image->url() }}"
                            alt="{{ $image->alt_text ?: $productName }}"
                            class="absolute inset-0 h-full w-full object-contain"
                            width="{{ $imageWidth }}"
                            height="{{ $imageHeight }}"
                            @if ($hasGallery) x-show="active === {{ $index }}" @endif
                        >
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
