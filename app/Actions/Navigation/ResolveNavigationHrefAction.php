<?php

namespace App\Actions\Navigation;

use App\Enums\NavigationLinkType;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\DiscoveryUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class ResolveNavigationHrefAction
{
    public function execute(
        ?NavigationLinkType $linkType,
        ?int $linkableId = null,
        ?string $routeKey = null,
        ?string $url = null,
    ): ?string {
        if ($linkType === null) {
            return null;
        }

        return match ($linkType) {
            NavigationLinkType::Relationship => $this->taxonomyHref(
                Relationship::class,
                $linkableId,
                fn (string $slug) => DiscoveryUrl::relationship($slug),
            ),
            NavigationLinkType::Occasion => $this->taxonomyHref(
                Occasion::class,
                $linkableId,
                fn (string $slug) => DiscoveryUrl::occasion($slug),
            ),
            NavigationLinkType::Interest => $this->taxonomyHref(
                Interest::class,
                $linkableId,
                fn (string $slug) => DiscoveryUrl::interest($slug),
            ),
            NavigationLinkType::Profession => $this->taxonomyHref(
                Profession::class,
                $linkableId,
                fn (string $slug) => DiscoveryUrl::profession($slug),
            ),
            NavigationLinkType::RecipientType => $this->taxonomyHref(
                RecipientType::class,
                $linkableId,
                fn (string $slug) => DiscoveryUrl::recipientType($slug),
            ),
            NavigationLinkType::GiftType => $this->taxonomyHref(
                GiftType::class,
                $linkableId,
                fn (string $slug) => DiscoveryUrl::giftType($slug),
            ),
            NavigationLinkType::Category => $this->categoryHref($linkableId),
            NavigationLinkType::SeoLandingPage => $this->seoLandingPageHref($linkableId),
            NavigationLinkType::DiscoveryRoute => $this->discoveryRouteHref($routeKey),
            NavigationLinkType::ExternalUrl => $this->externalUrlHref($url),
        };
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  callable(string): string  $url
     */
    private function taxonomyHref(string $modelClass, ?int $linkableId, callable $url): ?string
    {
        $record = $this->activeRecord($modelClass, $linkableId);

        if ($record === null || blank($record->slug)) {
            return null;
        }

        return $url((string) $record->slug);
    }

    private function categoryHref(?int $linkableId): ?string
    {
        $category = $this->activeRecord(Category::class, $linkableId);

        if ($category === null || blank($category->full_path)) {
            return null;
        }

        return DiscoveryUrl::giftIdeasCategory((string) $category->full_path);
    }

    private function seoLandingPageHref(?int $linkableId): ?string
    {
        if ($linkableId === null || $linkableId < 1) {
            return null;
        }

        $page = SeoLandingPage::query()->discoverable()->find($linkableId);

        if ($page === null || blank($page->slug)) {
            return null;
        }

        return DiscoveryUrl::seoLandingPage($page->slug);
    }

    private function discoveryRouteHref(?string $routeKey): ?string
    {
        if (! is_string($routeKey) || $routeKey === '') {
            return null;
        }

        $template = Arr::get(config('discovery.routes', []), $routeKey);

        if (! is_string($template) || $template === '') {
            return null;
        }

        if (preg_match('/\{[a-z_]+\}/', $template) === 1) {
            return null;
        }

        return DiscoveryUrl::route($routeKey);
    }

    private function externalUrlHref(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function activeRecord(string $modelClass, ?int $linkableId): ?Model
    {
        if ($linkableId === null || $linkableId < 1) {
            return null;
        }

        return $modelClass::query()
            ->whereKey($linkableId)
            ->where('is_active', true)
            ->first();
    }
}
