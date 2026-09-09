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

<div class="mx-auto w-full max-w-[640px]" @if ($hasGallery) x-data="{ active: 0 }" @endif>
    <div class="rounded-xl border border-line bg-surface p-3">
        <div class="aspect-square overflow-hidden rounded-md bg-surface-sunken p-3">
            @if ($images->isEmpty())
                <x-gift-image-placeholder class="h-full bg-transparent" />
            @elseif (! $hasGallery)
                <img
                    src="{{ $images->first()->url() }}"
                    alt="{{ $images->first()->alt_text ?: $productName }}"
                    class="h-full w-full object-contain"
                    width="{{ $imageWidth }}"
                    height="{{ $imageHeight }}"
                    fetchpriority="high"
                >
            @else
                <div class="relative h-full w-full">
                    @foreach ($images as $index => $image)
                        <img
                            src="{{ $image->url() }}"
                            alt="{{ $image->alt_text ?: $productName }}"
                            class="absolute inset-0 h-full w-full object-contain"
                            width="{{ $imageWidth }}"
                            height="{{ $imageHeight }}"
                            x-show="active === {{ $index }}"
                            @if ($index === 0)
                                fetchpriority="high"
                            @else
                                loading="lazy"
                                x-cloak
                            @endif
                        >
                    @endforeach
                </div>
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
</div>
