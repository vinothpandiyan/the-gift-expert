<?php

namespace App\Http\Controllers\Discovery;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSlugRedirect;
use App\Support\DiscoveryUrl;
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
            return view('discovery.gifts.show', [
                'product' => $product,
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

        return redirect(DiscoveryUrl::gift($target->slug), 301);
    }
}
