<?php

namespace App\Console\Commands;

use App\Actions\LaunchPublication\CaptureLaunchCatalogFingerprintAction;
use App\Actions\PublicationReadiness\DiagnosePublicationReadinessProductAction;
use App\Actions\PublicationReadiness\RemediatePublicationReadinessProductAction;
use App\Enums\PublicationReadinessRemediation;
use App\Models\Product;
use App\Models\User;
use App\PublicationReadiness\PublicationReadinessDiagnosis;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class RemediatePublicationReadinessCommand extends Command
{
    protected $signature = 'catalog:remediate-readiness
        {--execute : Apply approved remediations; default is diagnose-only}
        {--json : Output machine-readable JSON}
        {--product=* : Limit to these Product IDs}';

    protected $description = 'Diagnose or remediate retained draft gifts blocked on primary category or classification lifecycle.';

    public function handle(
        DiagnosePublicationReadinessProductAction $diagnose,
        RemediatePublicationReadinessProductAction $remediate,
        CaptureLaunchCatalogFingerprintAction $fingerprint,
    ): int {
        $beforeHash = $fingerprint->execute()->hash;
        $diagnoses = $this->selectedDiagnoses($diagnose);

        if (! $this->option('execute')) {
            $payload = [
                'mode' => 'preview',
                'fingerprint_hash' => $beforeHash,
                'attempted' => count($diagnoses),
                'diagnoses' => array_map(
                    fn ($diagnosis): array => $diagnosis->toArray(),
                    $diagnoses,
                ),
            ];

            if ($this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }

            $this->info('Publication readiness preview');
            $this->line('Fingerprint: '.$beforeHash);
            $this->line('Attempted: '.count($diagnoses));
            $this->newLine();

            foreach ($diagnoses as $diagnosis) {
                $this->line(sprintf(
                    '%d %s | %s | %s | %s',
                    $diagnosis->productId,
                    $diagnosis->title,
                    $diagnosis->code->letter(),
                    $diagnosis->remediation->value,
                    implode(',', $diagnosis->blockers),
                ));
            }

            $this->comment('Preview only. Pass --execute to apply approved remediations. Products are not published.');

            return self::SUCCESS;
        }

        $user = User::query()->orderBy('id')->first();

        if (! $user instanceof User) {
            $this->error('An operator user is required to record classification authority.');

            return self::FAILURE;
        }

        $results = [];
        $failed = 0;

        foreach ($diagnoses as $diagnosis) {
            $product = Product::query()->find($diagnosis->productId);

            if (! $product instanceof Product) {
                $failed++;
                $this->warn("Product {$diagnosis->productId} was not found.");

                continue;
            }

            try {
                $results[] = $remediate->execute($product, $user);
            } catch (ValidationException $exception) {
                $failed++;
                $this->warn("Product {$diagnosis->productId}: ".$exception->getMessage());
            }
        }

        $after = $fingerprint->execute();
        $ready = collect($results)->where('publishReady', true)->count();
        $blocked = collect($results)->where('publishReady', false)->count();
        $left = collect($results)->filter(
            fn ($result): bool => $result->diagnosis->remediation === PublicationReadinessRemediation::LeaveBlocked,
        )->count();

        $payload = [
            'mode' => 'execute',
            'fingerprint_before' => $beforeHash,
            'fingerprint_after' => $after->hash,
            'attempted' => count($diagnoses),
            'remediated' => collect($results)->where('publishReady', true)->count()
                + collect($results)->filter(
                    fn ($result): bool => ! $result->publishReady
                        && $result->diagnosis->remediation !== PublicationReadinessRemediation::LeaveBlocked,
                )->count(),
            'still_blocked' => $blocked,
            'now_publish_ready' => $ready,
            'left_blocked' => $left,
            'failed' => $failed,
            'published' => collect($results)->where('published', true)->count(),
            'archived' => collect($results)->where('archived', true)->count(),
            'results' => array_map(fn ($result): array => $result->toArray(), $results),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->info('Publication readiness execute');
        $this->line("Attempted: {$payload['attempted']}");
        $this->line("Now publish-ready: {$payload['now_publish_ready']}");
        $this->line("Still blocked: {$payload['still_blocked']}");
        $this->line("Failed: {$payload['failed']}");
        $this->comment('No Product was published or archived by this command.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<PublicationReadinessDiagnosis>
     */
    private function selectedDiagnoses(DiagnosePublicationReadinessProductAction $diagnose): array
    {
        $ids = array_values(array_filter(
            array_map('intval', (array) $this->option('product')),
            fn (int $id): bool => $id > 0,
        ));

        if ($ids === []) {
            return $diagnose->backlog();
        }

        $diagnoses = [];

        foreach ($ids as $id) {
            $product = Product::query()->find($id);

            if (! $product instanceof Product) {
                $this->warn("Product {$id} was not found.");

                continue;
            }

            $diagnosis = $diagnose->execute($product);

            if ($diagnosis !== null) {
                $diagnoses[] = $diagnosis;
            }
        }

        return $diagnoses;
    }
}
