<?php

namespace App\Http\Controllers\Discovery;

use App\Actions\Affiliate\CreateAffiliateClickAction;
use App\Http\Controllers\Controller;
use App\Models\AffiliateLink;
use Illuminate\Http\RedirectResponse;

class AffiliateRedirectController extends Controller
{
    public function __invoke(string $uuid, CreateAffiliateClickAction $action): RedirectResponse
    {
        $affiliateLink = AffiliateLink::query()
            ->where('uuid', $uuid)
            ->active()
            ->whereHas('product', function ($query): void {
                $query->published();
            })
            ->first();

        if ($affiliateLink === null) {
            abort(404);
        }

        $action->execute($affiliateLink);

        return redirect()->away($affiliateLink->url, 302);
    }
}
