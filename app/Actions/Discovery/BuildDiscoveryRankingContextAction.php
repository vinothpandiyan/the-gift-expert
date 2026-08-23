<?php

namespace App\Actions\Discovery;

use App\DiscoveryRanking\DiscoveryRankingContext;
use App\Models\Occasion;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\SeoLandingPageEditorial;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class BuildDiscoveryRankingContextAction
{
    public function fromRelationship(Relationship $relationship): DiscoveryRankingContext
    {
        return new DiscoveryRankingContext(
            surface: 'relationship',
            filters: ['relationship_id' => $relationship->id],
        );
    }

    public function fromOccasion(Occasion $occasion): DiscoveryRankingContext
    {
        return new DiscoveryRankingContext(
            surface: 'occasion',
            filters: ['occasion_id' => $occasion->id],
        );
    }

    public function fromSeoLandingPage(SeoLandingPage $page): DiscoveryRankingContext
    {
        return new DiscoveryRankingContext(
            surface: 'seo_landing',
            filters: SeoLandingPageEditorial::productFilters($page),
            matchAllInterests: true,
        );
    }

    public function fromTaxonomy(string $taxonomy, Model $record): DiscoveryRankingContext
    {
        return match ($taxonomy) {
            'relationship' => $this->fromRelationship($record instanceof Relationship ? $record : throw new InvalidArgumentException('Expected Relationship model.')),
            'occasion' => $this->fromOccasion($record instanceof Occasion ? $record : throw new InvalidArgumentException('Expected Occasion model.')),
            default => throw new InvalidArgumentException("Taxonomy [{$taxonomy}] does not support discovery ranking."),
        };
    }
}
