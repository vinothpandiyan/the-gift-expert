<?php

namespace App\Actions\CuratedCatalog;

use App\CommercialSourcing\CommercialEnrichmentException;
use App\CommercialSourcing\OpenAiCompatibleCommercialEnrichmentClient;
use App\CuratedCatalog\CuratedProductSeoPrompt;
use App\Enums\ProductStatus;
use App\Enums\SeoOwnership;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class GenerateCuratedProductSeoAction
{
    public function __construct(
        private CuratedProductSeoPrompt $prompt,
        private OpenAiCompatibleCommercialEnrichmentClient $client,
    ) {}

    /**
     * @return array{changed: bool, meta_title: string, meta_description: string}
     */
    public function execute(Product $product): array
    {
        $product = ($product->fresh() ?? $product)->loadMissing($this->taxonomyRelations());

        if ($product->status !== ProductStatus::Draft) {
            throw new CommercialEnrichmentException('SEO generation only accepts draft products.');
        }

        if (! $product->seoNeedsAiGeneration()) {
            throw new CommercialEnrichmentException('SEO is human-owned or already uses the current AI generation.');
        }

        if (blank($product->name) || (blank($product->short_description) && blank($product->description))) {
            throw new CommercialEnrichmentException('Clean editorial title and copy are required before SEO generation.');
        }

        $messages = $this->prompt->messages($product);
        $decoded = $this->client->complete(
            $messages['system'],
            $messages['user'],
            $messages['schema'],
            requireTaxonomy: false,
        );
        $title = $this->limitLength(
            $this->requiredString($decoded['meta_title'] ?? null, 'meta title'),
            (int) config('curated_catalog.seo.meta_title_max_length', 60),
        );
        $description = $this->limitLength(
            $this->requiredString($decoded['meta_description'] ?? null, 'meta description'),
            (int) config('curated_catalog.seo.meta_description_max_length', 160),
            finishSentence: true,
        );
        $previousTitle = $product->meta_title;
        $previousDescription = $product->meta_description;

        $product = DB::transaction(function () use ($product, $title, $description): Product {
            $fresh = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($fresh->status !== ProductStatus::Draft || ! $fresh->seoNeedsAiGeneration()) {
                throw new CommercialEnrichmentException('SEO ownership changed while metadata was being generated.');
            }

            $fresh->meta_title = $title;
            $fresh->meta_description = $description;
            $fresh->seo_ownership = SeoOwnership::Ai;
            $fresh->seo_generation_version = (int) config('curated_catalog.seo.version', 1);
            $fresh->seo_reviewed_at = null;
            $fresh->seo_reviewed_by_user_id = null;
            $fresh->save();

            return $fresh;
        });

        return [
            'changed' => $product->meta_title !== $previousTitle
                || $product->meta_description !== $previousDescription,
            'meta_title' => (string) $product->meta_title,
            'meta_description' => (string) $product->meta_description,
        ];
    }

    /**
     * @return list<string>
     */
    private function taxonomyRelations(): array
    {
        return [
            'categories:id,name',
            'relationships:id,name',
            'recipientTypes:id,name',
            'occasions:id,name',
            'interests:id,name',
            'professions:id,name',
            'giftTypes:id,name',
        ];
    }

    private function requiredString(mixed $value, string $field): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            throw new CommercialEnrichmentException("The generated {$field} was empty.");
        }

        return $value;
    }

    private function limitLength(string $value, int $maximum, bool $finishSentence = false): string
    {
        if (mb_strlen($value) <= $maximum) {
            return $value;
        }

        $suffix = $finishSentence ? '.' : '';
        $limited = mb_substr($value, 0, max(1, $maximum - mb_strlen($suffix)));
        $lastSpace = mb_strrpos($limited, ' ');

        if ($lastSpace !== false) {
            $limited = mb_substr($limited, 0, $lastSpace);
        }

        return rtrim($limited, " \t\n\r\0\x0B,;:.!?-").$suffix;
    }
}
