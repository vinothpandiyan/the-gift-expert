<?php

namespace App\Http\Controllers\Discovery;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSlugRedirect;
use App\Support\DiscoveryUrl;
use App\Support\PageMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class GiftController extends Controller
{
    public function show(string $slug): RedirectResponse|View
    {
        $product = Product::query()
            ->published()
            ->where('slug', $slug)
            ->with([
                'images' => fn ($query) => $query
                    ->orderByDesc('is_primary')
                    ->orderBy('sort_order'),
                'affiliateLinks' => fn ($query) => $query
                    ->active()
                    ->with('merchant')
                    ->orderByDesc('is_primary'),
                'categories',
                'occasions',
                'relationships',
                'recipientTypes',
                'interests',
                'professions',
                'giftTypes',
            ])
            ->first();

        if ($product !== null) {
            $context = request()->query('context');

            return view('discovery.gifts.show', [
                'product' => $product,
                'seoTitle' => PageMeta::giftTitle($product),
                'seoDescription' => PageMeta::giftDescription($product),
                'seoCanonical' => PageMeta::giftCanonical($product),
                'seoRobots' => 'index, follow',
                'breadcrumbs' => PageMeta::giftBreadcrumbs(
                    $product,
                    is_string($context) ? $context : null,
                ),
            ]);
        }

        $redirect = ProductSlugRedirect::query()
            ->where('from_slug', $slug)
            ->first();

        if ($redirect === null) {
            abort(404);
        }

        $target = Product::query()
            ->published()
            ->where('slug', $redirect->to_slug)
            ->first();

        if ($target === null) {
            abort(404);
        }

        $context = request()->query('context');

        return redirect(DiscoveryUrl::gift(
            $target->slug,
            context: is_string($context) && $context !== '' ? $context : null,
        ), 301);
    }
}
