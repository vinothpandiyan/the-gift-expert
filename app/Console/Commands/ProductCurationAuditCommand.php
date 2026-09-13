<?php

namespace App\Console\Commands;

use App\Actions\CatalogCuration\BuildProductCurationEvidenceAction;
use App\Actions\CatalogCuration\CalculateCatalogCurationContextAction;
use App\Actions\CatalogCuration\RunProductCurationAuditAction;
use App\Enums\ProductCurationAuditOutcome;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationAuditRun;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ProductCurationAuditCommand extends Command
{
    protected $signature = 'catalog:curation-audit
        {--product=* : Product ID; repeatable}
        {--status=* : Product status; repeatable}
        {--only-missing : Select products with no completed curation audit}
        {--force : Recalculate selected products; unchanged compatible semantics may be reused}
        {--limit= : Maximum number of products}
        {--dry-run : Select and report only; perform no AI calls or writes}
        {--run= : Resume pending/semantic-ready stages; failed rows stay historical and require a new run}';

    protected $description = 'Run the advisory Phase 22B product curation audit.';

    public function handle(
        RunProductCurationAuditAction $runAudit,
        BuildProductCurationEvidenceAction $buildEvidence,
        CalculateCatalogCurationContextAction $calculateContext,
    ): int {
        if ($this->option('dry-run') && filled($this->option('run'))) {
            $this->error('--dry-run cannot be combined with --run.');

            return self::FAILURE;
        }

        $run = $this->resumeRun();

        if (filled($this->option('run')) && ! $run instanceof ProductCurationAuditRun) {
            return self::FAILURE;
        }

        $productIds = $run instanceof ProductCurationAuditRun
            ? $run->audits()
                ->whereIn('outcome', [ProductCurationAuditOutcome::Pending, ProductCurationAuditOutcome::SemanticReady])
                ->orderBy('id')
                ->pluck('product_id')
                ->map(fn ($id): int => (int) $id)
                ->all()
            : $this->selectedProductIds($buildEvidence, $calculateContext);

        if ($productIds === null) {
            return self::FAILURE;
        }

        $this->info(sprintf('Selected %d product(s).', count($productIds)));

        if ($this->option('dry-run')) {
            $this->line($productIds === [] ? 'No products matched.' : 'Product IDs: '.implode(', ', $productIds));
            $this->info('Dry run completed. No AI requests or database writes were performed.');

            return self::SUCCESS;
        }

        if ($productIds === [] && ! $run instanceof ProductCurationAuditRun) {
            $this->comment('No products matched; creating a completed empty run record.');
        }

        $options = [
            'product' => $this->option('product'),
            'status' => $this->option('status'),
            'only_missing' => (bool) $this->option('only-missing'),
            'force' => (bool) $this->option('force'),
            'limit' => $this->option('limit'),
        ];
        $progress = function (string $stage, ProductCurationAudit $audit): void {
            $message = sprintf(
                '[%s] product=%d audit=%d%s',
                $stage,
                $audit->product_id,
                $audit->id,
                $audit->failure ? ' error='.$audit->failure : '',
            );
            $stage === 'failed' ? $this->warn($message) : $this->line($message);
        };
        $completedRun = $runAudit->execute($productIds, $options, $run, $progress);

        $this->newLine();
        $this->info("Curation audit run {$completedRun->id}: {$completedRun->status->value}");
        $this->table(
            ['Total', 'Processed', 'Completed', 'Failed'],
            [[
                $completedRun->products_total,
                $completedRun->products_processed,
                $completedRun->products_completed,
                $completedRun->products_failed,
            ]],
        );

        return $completedRun->products_failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<int>|null
     */
    private function selectedProductIds(
        BuildProductCurationEvidenceAction $buildEvidence,
        CalculateCatalogCurationContextAction $calculateContext,
    ): ?array {
        $query = Product::query()->orderBy('id');
        $products = array_values(array_filter((array) $this->option('product'), fn ($id): bool => $id !== null && $id !== ''));

        if ($products !== []) {
            foreach ($products as $id) {
                if (! is_string($id) || ! ctype_digit($id) || (int) $id < 1) {
                    $this->error("Invalid --product value [{$id}].");

                    return null;
                }
            }

            $query->whereKey(array_map('intval', $products));
        }

        $statuses = array_values(array_filter((array) $this->option('status'), fn ($status): bool => $status !== null && $status !== ''));

        if ($statuses !== []) {
            foreach ($statuses as $status) {
                if (! is_string($status) || ProductStatus::tryFrom($status) === null) {
                    $this->error("Invalid --status value [{$status}].");

                    return null;
                }
            }

            $query->whereIn('status', $statuses);
        }

        if ($this->option('only-missing')) {
            $query->whereDoesntHave('curationAudits', function (Builder $audit): void {
                $audit->where('outcome', ProductCurationAuditOutcome::Completed);
            });
        }

        $limit = $this->option('limit');

        if ($limit !== null && $limit !== '') {
            if (! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1) {
                $this->error('--limit must be a positive integer.');

                return null;
            }

        }

        $products = $query->get();

        if (! $this->option('only-missing') && ! $this->option('force')) {
            $products = $products->reject(function (Product $product) use ($buildEvidence, $calculateContext): bool {
                $evidenceFingerprint = $buildEvidence->execute($product)->fingerprint();
                $semanticFingerprint = hash('sha256', implode('|', [
                    $evidenceFingerprint,
                    (string) config('catalog_curation.versions.semantic_evaluator'),
                    (string) config('catalog_curation.versions.prompt'),
                ]));
                $audit = $product->curationAudits()
                    ->where('outcome', ProductCurationAuditOutcome::Completed)
                    ->where('semantic_fingerprint', $semanticFingerprint)
                    ->where('scoring_version', config('catalog_curation.versions.scoring'))
                    ->where('context_version', config('catalog_curation.versions.context'))
                    ->whereNotNull('context_fingerprint')
                    ->whereNotNull('context_calculated_at')
                    ->latest('completed_at')
                    ->first();

                if (! $audit instanceof ProductCurationAudit) {
                    return false;
                }

                return hash_equals(
                    (string) $audit->context_fingerprint,
                    $calculateContext->execute($audit, preferCurrentRun: false)['fingerprint'],
                );
            });
        }

        return $products
            ->when($limit !== null && $limit !== '', fn ($rows) => $rows->take((int) $limit))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function resumeRun(): ?ProductCurationAuditRun
    {
        $runId = $this->option('run');

        if ($runId === null || $runId === '') {
            return null;
        }

        if (! is_string($runId)) {
            $this->error('Invalid --run UUID.');

            return null;
        }

        $run = ProductCurationAuditRun::query()->find($runId);

        if (! $run instanceof ProductCurationAuditRun) {
            $this->error("Curation audit run [{$runId}] was not found.");
        }

        return $run;
    }
}
