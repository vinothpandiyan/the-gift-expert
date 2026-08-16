<?php

namespace App\Observers;

use App\Actions\SeoLandingPage\RecordSeoLandingPageSlugRedirectAction;
use App\Models\SeoLandingPage;

class SeoLandingPageObserver
{
    public function updated(SeoLandingPage $seoLandingPage): void
    {
        if (! $seoLandingPage->wasChanged('slug')) {
            return;
        }

        app(RecordSeoLandingPageSlugRedirectAction::class)->execute(
            fromSlug: (string) $seoLandingPage->getOriginal('slug'),
            toSlug: $seoLandingPage->slug,
            seoLandingPageId: $seoLandingPage->id,
        );
    }
}
