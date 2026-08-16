<?php

namespace App\Actions\SeoLandingPage;

use App\Enums\SeoLandingPageStatus;
use App\Models\SeoLandingPage;
use App\Support\SeoLandingPageEditorial;
use Illuminate\Validation\ValidationException;

class PublishSeoLandingPageAction
{
    public function execute(SeoLandingPage $page): void
    {
        $errors = [];

        if ($page->trashed()) {
            $errors[] = 'A deleted SEO landing page cannot be published.';
        }

        if (! $this->hasAnyFilterDimension($page)) {
            $errors[] = 'Add at least one filter dimension before publishing.';
        }

        $duplicate = SeoLandingPageEditorial::findPublishedDuplicate($page);

        if ($duplicate instanceof SeoLandingPage) {
            $errors[] = "Another published SEO landing page already uses this filter combination ({$duplicate->heading}).";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'status' => $errors,
            ]);
        }

        $page->status = SeoLandingPageStatus::Published;

        if ($page->published_at === null) {
            $page->published_at = now();
        }

        $page->save();
    }

    private function hasAnyFilterDimension(SeoLandingPage $page): bool
    {
        return filled($page->occasion_id)
            || filled($page->relationship_id)
            || filled($page->recipient_type_id)
            || filled($page->profession_id)
            || filled($page->gift_type_id)
            || filled($page->category_id)
            || filled($page->budget_range_id)
            || $page->interests()->exists();
    }
}
