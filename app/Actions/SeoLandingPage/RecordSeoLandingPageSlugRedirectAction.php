<?php

namespace App\Actions\SeoLandingPage;

use App\Models\SeoLandingPageRedirect;
use Illuminate\Support\Facades\DB;

class RecordSeoLandingPageSlugRedirectAction
{
    public function execute(string $fromSlug, string $toSlug, ?int $seoLandingPageId = null): void
    {
        if ($fromSlug === $toSlug) {
            return;
        }

        DB::transaction(function () use ($fromSlug, $toSlug, $seoLandingPageId): void {
            SeoLandingPageRedirect::query()
                ->where('to_slug', $fromSlug)
                ->update(['to_slug' => $toSlug]);

            SeoLandingPageRedirect::query()->updateOrCreate(
                ['from_slug' => $fromSlug],
                [
                    'to_slug' => $toSlug,
                    'seo_landing_page_id' => $seoLandingPageId,
                ],
            );
        });
    }
}
