<?php

namespace App\Console\Commands;

use App\Actions\Product\EvaluateAndPersistProductAutomationReadinessAction;
use App\Enums\ProductStatus;
use App\Models\CatalogCandidateSourcingItem;
use App\Models\Product;
use Illuminate\Console\Command;
use InvalidArgumentException;

class EvaluateProductAutomationReadinessCommand extends Command
{
    protected $signature = 'catalog:readiness
        {--product= : Evaluate the latest promoted sourcing item for a product ID}
        {--item= : Evaluate a specific sourcing item ID}
        {--all-drafts : Evaluate all draft products with promoted sourcing items}';

    protected $description = 'Evaluate and persist product automation readiness for sourcing items.';

    public function handle(EvaluateAndPersistProductAutomationReadinessAction $evaluate): int
    {
        $itemOption = $this->option('item');
        $productOption = $this->option('product');
        $allDrafts = (bool) $this->option('all-drafts');

        if ($itemOption !== null && $itemOption !== '') {
            $this->evaluateItem($evaluate, (int) $itemOption);

            return self::SUCCESS;
        }

        if ($productOption !== null && $productOption !== '') {
            $this->evaluateProduct($evaluate, (int) $productOption);

            return self::SUCCESS;
        }

        if ($allDrafts) {
            $this->evaluateAllDrafts($evaluate);

            return self::SUCCESS;
        }

        $this->error('Specify --item, --product, or --all-drafts.');

        return self::FAILURE;
    }

    private function evaluateItem(
        EvaluateAndPersistProductAutomationReadinessAction $evaluate,
        int $itemId,
    ): void {
        $item = CatalogCandidateSourcingItem::query()->find($itemId);

        if ($item === null) {
            throw new InvalidArgumentException("Sourcing item [{$itemId}] was not found.");
        }

        $this->printRow($evaluate->execute($item));
    }

    private function evaluateProduct(
        EvaluateAndPersistProductAutomationReadinessAction $evaluate,
        int $productId,
    ): void {
        $item = CatalogCandidateSourcingItem::query()
            ->where('product_id', $productId)
            ->orderByDesc('id')
            ->first();

        if ($item === null) {
            throw new InvalidArgumentException("No sourcing item found for product [{$productId}].");
        }

        $this->printRow($evaluate->execute($item));
    }

    private function evaluateAllDrafts(EvaluateAndPersistProductAutomationReadinessAction $evaluate): void
    {
        $productIds = Product::query()
            ->where('status', ProductStatus::Draft)
            ->whereHas('sourcingItems', fn ($query) => $query->whereNotNull('product_id'))
            ->pluck('id');

        foreach ($productIds as $productId) {
            $item = CatalogCandidateSourcingItem::query()
                ->where('product_id', $productId)
                ->orderByDesc('id')
                ->first();

            if ($item === null) {
                continue;
            }

            $this->printRow($evaluate->execute($item));
        }
    }

    private function printRow(CatalogCandidateSourcingItem $item): void
    {
        $codes = is_array($item->exception_codes) ? implode(', ', $item->exception_codes) : '';

        $this->line(sprintf(
            'item=%d product=%s readiness=%s codes=%s',
            $item->id,
            $item->product_id ?? '—',
            $item->readiness?->value ?? '—',
            $codes === '' ? '—' : $codes,
        ));
    }
}
