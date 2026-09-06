<?php

namespace App\Http\Controllers\Discovery;

use App\Actions\Discovery\QueryDiscoveryListingProductsAction;
use App\Actions\SeoLandingPage\QueryDiscoverableSeoLandingPagesAction;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Http\Controllers\Controller;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Support\PageMeta;
use App\Support\Terminology;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;
use InvalidArgumentException;

class TaxonomyController extends Controller
{
    /**
     * @var array<string, class-string<Model>>
     */
    private const MODELS = [
        'occasion' => Occasion::class,
        'relationship' => Relationship::class,
        'recipient_type' => RecipientType::class,
        'interest' => Interest::class,
        'profession' => Profession::class,
        'gift_type' => GiftType::class,
    ];

    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'occasion' => 'Occasion',
        'relationship' => 'Relationship',
        'recipient_type' => 'Recipient',
        'interest' => 'Interest',
        'profession' => 'Profession',
        'gift_type' => 'Gift type',
    ];

    public function show(
        string $slug,
        string $taxonomy,
        QueryDiscoverableSeoLandingPagesAction $queryLandingPages,
        QueryDiscoveryListingProductsAction $queryListing,
    ): View {
        $modelClass = self::MODELS[$taxonomy] ?? null;

        if ($modelClass === null) {
            throw new InvalidArgumentException("Unknown discovery taxonomy [{$taxonomy}].");
        }

        $record = $modelClass::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if ($record === null) {
            abort(404);
        }

        $listingContext = DiscoveryListingContext::forTaxonomy($taxonomy, $record);
        $seo = $this->listingSeo(
            $listingContext,
            PageMeta::taxonomyCanonical($record, $taxonomy),
            $queryListing,
        );

        return view('discovery.taxonomies.show', [
            'taxonomy' => $record,
            'taxonomyKey' => $taxonomy,
            'taxonomyLabel' => self::LABELS[$taxonomy],
            'heading' => $this->heading($taxonomy, $record),
            'listingContext' => $listingContext->toArray(),
            'relatedLandingPages' => $queryLandingPages->execute($this->landingPageFilters($taxonomy, $record->id)),
            'seoTitle' => PageMeta::taxonomyTitle($record, $taxonomy),
            'seoDescription' => PageMeta::taxonomyDescription($record),
            'seoCanonical' => $seo['canonical'],
            'seoRobots' => $seo['robots'],
            'seoPrev' => $seo['prev'],
            'seoNext' => $seo['next'],
            'breadcrumbs' => PageMeta::taxonomyBreadcrumbs($record, $taxonomy, self::LABELS[$taxonomy]),
        ]);
    }

    private function heading(string $taxonomy, Model $record): string
    {
        $name = (string) $record->name;

        return match ($taxonomy) {
            'relationship', 'recipient_type', 'profession' => Terminology::gifts().' for '.$name,
            'occasion' => $name.' '.Terminology::gifts(),
            'interest' => $name.' '.Terminology::giftIdeas(),
            default => $name,
        };
    }

    /**
     * @return array{canonical: string, robots: string, prev: ?string, next: ?string}
     */
    private function listingSeo(
        DiscoveryListingContext $listingContext,
        string $baseCanonical,
        QueryDiscoveryListingProductsAction $queryListing,
    ): array {
        $state = DiscoveryListingQueryState::fromRequest(request())->scopedTo($listingContext);
        $perPage = max(1, (int) config('discovery_ranking.per_page', 12));
        $total = $state->hasUserFiltersOrSort()
            ? 0
            : $queryListing->count($listingContext, $state);

        return PageMeta::listingSeo($baseCanonical, $total, $perPage);
    }

    /**
     * @return array<string, int>
     */
    private function landingPageFilters(string $taxonomy, int $id): array
    {
        return match ($taxonomy) {
            'occasion' => ['occasion_id' => $id],
            'relationship' => ['relationship_id' => $id],
            'recipient_type' => ['recipient_type_id' => $id],
            'profession' => ['profession_id' => $id],
            'gift_type' => ['gift_type_id' => $id],
            'interest' => ['interest_id' => $id],
            default => [],
        };
    }
}
