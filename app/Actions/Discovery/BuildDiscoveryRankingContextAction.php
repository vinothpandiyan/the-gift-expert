<?php

namespace App\Actions\Discovery;

use App\DiscoveryRanking\DiscoveryRankingContext;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
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

    public function fromRecipientType(RecipientType $recipientType): DiscoveryRankingContext
    {
        return new DiscoveryRankingContext(
            surface: 'recipient_type',
            filters: ['recipient_type_id' => $recipientType->id],
        );
    }

    public function fromInterest(Interest $interest): DiscoveryRankingContext
    {
        return new DiscoveryRankingContext(
            surface: 'interest',
            filters: ['interest_ids' => [$interest->id]],
        );
    }

    public function fromProfession(Profession $profession): DiscoveryRankingContext
    {
        return new DiscoveryRankingContext(
            surface: 'profession',
            filters: ['profession_id' => $profession->id],
        );
    }

    public function fromGiftType(GiftType $giftType): DiscoveryRankingContext
    {
        return new DiscoveryRankingContext(
            surface: 'gift_type',
            filters: ['gift_type_id' => $giftType->id],
        );
    }

    public function fromCategory(Category $category): DiscoveryRankingContext
    {
        return new DiscoveryRankingContext(
            surface: 'category',
            filters: ['category_id' => $category->id],
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
            'recipient_type' => $this->fromRecipientType($record instanceof RecipientType ? $record : throw new InvalidArgumentException('Expected RecipientType model.')),
            'interest' => $this->fromInterest($record instanceof Interest ? $record : throw new InvalidArgumentException('Expected Interest model.')),
            'profession' => $this->fromProfession($record instanceof Profession ? $record : throw new InvalidArgumentException('Expected Profession model.')),
            'gift_type' => $this->fromGiftType($record instanceof GiftType ? $record : throw new InvalidArgumentException('Expected GiftType model.')),
            default => throw new InvalidArgumentException("Taxonomy [{$taxonomy}] does not support discovery ranking."),
        };
    }
}
