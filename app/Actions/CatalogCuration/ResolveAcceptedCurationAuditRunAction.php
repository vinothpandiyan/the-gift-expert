<?php

namespace App\Actions\CatalogCuration;

use App\Enums\ProductCurationRunStatus;
use App\Models\ProductCurationAuditRun;

class ResolveAcceptedCurationAuditRunAction
{
    private bool $resolved = false;

    private ?ProductCurationAuditRun $accepted = null;

    public function execute(): ?ProductCurationAuditRun
    {
        if ($this->resolved) {
            return $this->accepted;
        }

        $this->accepted = ProductCurationAuditRun::query()
            ->whereIn('status', [
                ProductCurationRunStatus::Completed,
                ProductCurationRunStatus::CompletedWithErrors,
            ])
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->orderByDesc('created_at')
            ->get()
            ->first(fn (ProductCurationAuditRun $run): bool => $this->isFullCatalog($run));
        $this->resolved = true;

        return $this->accepted;
    }

    public function flush(): void
    {
        $this->resolved = false;
        $this->accepted = null;
    }

    public function require(): ProductCurationAuditRun
    {
        $run = $this->execute();

        if (! $run instanceof ProductCurationAuditRun) {
            throw new \RuntimeException('No accepted full-catalog curation audit run is available.');
        }

        return $run;
    }

    public function isFullCatalog(ProductCurationAuditRun $run): bool
    {
        $options = is_array($run->options) ? $run->options : [];
        $products = $options['product'] ?? [];
        $onlyMissing = $options['only_missing'] ?? false;
        $limit = $options['limit'] ?? null;

        if (is_array($products) && $products !== []) {
            return false;
        }

        if (! is_array($products) && filled($products)) {
            return false;
        }

        if ($onlyMissing === true || $onlyMissing === 1 || $onlyMissing === '1' || $onlyMissing === 'true') {
            return false;
        }

        return $limit === null || $limit === '' || $limit === false;
    }
}
