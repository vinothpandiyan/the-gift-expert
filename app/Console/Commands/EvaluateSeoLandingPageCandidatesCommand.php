<?php

namespace App\Console\Commands;

use App\Support\SeoLandingPageCandidateCatalog;
use Illuminate\Console\Command;

class EvaluateSeoLandingPageCandidatesCommand extends Command
{
    protected $signature = 'seo-landing-pages:evaluate-candidates';

    protected $description = 'Print the editorial SEO landing page candidate matrix using live product filters.';

    public function handle(): int
    {
        $rows = collect(SeoLandingPageCandidateCatalog::evaluate())
            ->map(fn (array $row): array => [
                $row['name'],
                $row['slug'],
                $row['published_product_count'],
                $row['recommendation'],
                $row['cannibalization_risk'],
                $row['reason'],
            ])
            ->all();

        $this->table(
            ['Name', 'Slug', 'Published gifts', 'Recommendation', 'Cannibalization', 'Reason'],
            $rows,
        );

        return self::SUCCESS;
    }
}
