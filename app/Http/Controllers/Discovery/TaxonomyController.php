<?php

namespace App\Http\Controllers\Discovery;

use App\Actions\SeoLandingPage\QueryDiscoverableSeoLandingPagesAction;
use App\Http\Controllers\Controller;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Support\PageMeta;
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

    public function show(string $slug, string $taxonomy, QueryDiscoverableSeoLandingPagesAction $queryLandingPages): View
    {
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

        $products = $record->products()
            ->published()
            ->with([
                'images' => fn ($query) => $query
                    ->orderByDesc('is_primary')
                    ->orderBy('sort_order'),
                'affiliateLinks' => fn ($query) => $query
                    ->active()
                    ->with('merchant')
                    ->orderByDesc('is_primary'),
            ])
            ->orderByDesc('published_at')
            ->paginate(12);

        $pagination = PageMeta::paginatedCanonicals(
            $products,
            PageMeta::taxonomyCanonical($record, $taxonomy),
        );

        return view('discovery.taxonomies.show', [
            'taxonomy' => $record,
            'taxonomyKey' => $taxonomy,
            'taxonomyLabel' => self::LABELS[$taxonomy],
            'products' => $products,
            'relatedLandingPages' => $queryLandingPages->execute($this->landingPageFilters($taxonomy, $record->id)),
            'seoTitle' => PageMeta::taxonomyTitle($record, $taxonomy),
            'seoDescription' => PageMeta::taxonomyDescription($record),
            'seoCanonical' => $pagination['canonical'],
            'seoRobots' => 'index, follow',
            'seoPrev' => $pagination['prev'],
            'seoNext' => $pagination['next'],
            'breadcrumbs' => PageMeta::taxonomyBreadcrumbs($record, $taxonomy, self::LABELS[$taxonomy]),
        ]);
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
