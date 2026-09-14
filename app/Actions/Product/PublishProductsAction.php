<?php

namespace App\Actions\Product;

use App\Enums\LaunchPublicationResult;
use App\Enums\ProductStatus;
use App\LaunchPublication\LaunchPublicationAttempt;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PublishProductsAction
{
    public function __construct(
        private PublishProductAction $publishProduct,
    ) {}

    /**
     * Controlled bulk publication. Each Product is published through PublishProductAction.
     * This orchestrator does not implement an independent readiness rule.
     *
     * Per-Product work is wrapped in a database transaction so a failure cannot
     * leave that Product partially published. Unrelated Products are attempted
     * independently; the batch is not atomic.
     *
     * @param  iterable<int, Product|int>  $products
     * @return Collection<int, LaunchPublicationAttempt>
     */
    public function execute(iterable $products, string $actor = 'publish-products'): Collection
    {
        $attempts = collect();

        foreach ($products as $item) {
            $attempts->push($this->attempt($item, $actor));
        }

        return $attempts->values();
    }

    private function attempt(Product|int $item, string $actor): LaunchPublicationAttempt
    {
        $productId = $item instanceof Product ? (int) $item->id : (int) $item;
        $product = $item instanceof Product
            ? $item->fresh()
            : Product::query()->find($productId);

        if (! $product instanceof Product) {
            return LaunchPublicationAttempt::failed($productId, 'missing_product');
        }

        $title = (string) $product->name;
        $previous = $product->status?->value;

        if ($product->status === ProductStatus::Published) {
            return LaunchPublicationAttempt::skipped(
                $productId,
                'already_published',
                $title,
                previousStatus: $previous,
            );
        }

        if ($product->status !== ProductStatus::Draft) {
            return LaunchPublicationAttempt::skipped(
                $productId,
                'not_draft',
                $title,
                previousStatus: $previous,
            );
        }

        try {
            $result = DB::transaction(function () use ($product): array {
                return $this->publishProduct->execute($product->fresh());
            });
        } catch (ValidationException $exception) {
            $messages = collect($exception->errors())->flatten()->filter()->values()->all();

            return LaunchPublicationAttempt::failed(
                $productId,
                $messages === [] ? 'publication_requirements' : implode(' ', $messages),
                $title,
                previousStatus: $previous,
            );
        } catch (Throwable $exception) {
            report($exception);

            return LaunchPublicationAttempt::failed(
                $productId,
                'unexpected_exception: '.$exception->getMessage(),
                $title,
                previousStatus: $previous,
            );
        }

        $fresh = $product->fresh();

        return LaunchPublicationAttempt::published(
            productId: $productId,
            title: $title,
            humanDecision: null,
            previousStatus: $previous,
            newStatus: $fresh?->status?->value ?? ProductStatus::Published->value,
            publishedAt: $fresh?->published_at?->toIso8601String(),
            actor: $actor,
            warnings: $result['warnings'] ?? [],
        );
    }

    /**
     * @param  Collection<int, LaunchPublicationAttempt>  $attempts
     * @return array{published: int, skipped: int, failed: int}
     */
    public static function tally(Collection $attempts): array
    {
        return [
            'published' => $attempts->where('result', LaunchPublicationResult::Published)->count(),
            'skipped' => $attempts->where('result', LaunchPublicationResult::Skipped)->count(),
            'failed' => $attempts->where('result', LaunchPublicationResult::Failed)->count(),
        ];
    }
}
