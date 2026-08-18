<?php

namespace App\Console\Commands;

use App\Actions\CatalogCandidate\IngestCatalogCandidatesAction;
use App\CatalogCandidate\Ingestion\CatalogCandidateIngestionParser;
use App\CatalogCandidate\Ingestion\CatalogCandidateIngestionParserException;
use App\CatalogCandidate\Ingestion\CatalogCandidateIngestionResult;
use App\CatalogCandidate\Ingestion\CsvCatalogCandidateParser;
use App\CatalogCandidate\Ingestion\JsonCatalogCandidateParser;
use App\Enums\CatalogCandidateIngestionFormat;
use App\Enums\CatalogCandidateIngestionItemStatus;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class ImportCatalogCandidatesCommand extends Command
{
    protected $signature = 'catalog-candidates:import {file : Local CSV or JSON file} {--format= : csv or json} {--dry-run : Parse and validate without writing}';

    protected $description = 'Ingest catalog candidates from a local CSV or JSON file without creating gifts.';

    public function handle(IngestCatalogCandidatesAction $ingest): int
    {
        try {
            $path = $this->resolveLocalFile((string) $this->argument('file'));
            $format = $this->resolveFormat($path);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $maxBytes = (int) config('catalog_candidate_ingestion.max_file_bytes');
        $size = filesize($path);

        if ($size === false || $size > $maxBytes) {
            $this->error('The ingestion file exceeds the maximum allowed size.');

            return self::FAILURE;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            $this->error('The ingestion file could not be read.');

            return self::FAILURE;
        }

        try {
            $rows = iterator_to_array($this->parser($format)->parse($contents), false);
        } catch (CatalogCandidateIngestionParserException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $result = $ingest->execute(
                $rows,
                $format,
                basename($path),
                (bool) $this->option('dry-run'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->printResult($result);

        if ($result->itemsFailed > 0) {
            $this->warn('Some items failed. The batch was not aborted.');
        }

        return self::SUCCESS;
    }

    private function resolveLocalFile(string $path): string
    {
        $normalized = strtolower($path);

        foreach (['http://', 'https://', 'ftp://', 'php://', 'file://'] as $scheme) {
            if (str_starts_with($normalized, $scheme)) {
                throw new InvalidArgumentException('Remote URLs are not allowed. Provide a local file path.');
            }
        }

        $realPath = realpath($path);

        if ($realPath === false || ! is_file($realPath) || ! is_readable($realPath)) {
            throw new InvalidArgumentException("File [{$path}] was not found or is not readable.");
        }

        return $realPath;
    }

    private function resolveFormat(string $path): CatalogCandidateIngestionFormat
    {
        $explicit = $this->option('format');

        if (is_string($explicit) && $explicit !== '') {
            $format = CatalogCandidateIngestionFormat::tryFrom(strtolower($explicit));

            if ($format === null) {
                throw new InvalidArgumentException('Unsupported format. Use csv or json.');
            }

            return $format;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv' => CatalogCandidateIngestionFormat::Csv,
            'json' => CatalogCandidateIngestionFormat::Json,
            default => throw new InvalidArgumentException('Unable to infer format. Pass --format=csv or --format=json.'),
        };
    }

    private function parser(CatalogCandidateIngestionFormat $format): CatalogCandidateIngestionParser
    {
        return match ($format) {
            CatalogCandidateIngestionFormat::Csv => app(CsvCatalogCandidateParser::class),
            CatalogCandidateIngestionFormat::Json => app(JsonCatalogCandidateParser::class),
        };
    }

    private function printResult(CatalogCandidateIngestionResult $result): void
    {
        if ($this->option('dry-run')) {
            $this->info('Dry run completed. No catalog candidates were written.');
        } elseif ($result->run !== null) {
            $this->info("Ingestion run {$result->run->id} is {$result->run->status->value}.");
        }

        $this->line("Total: {$result->itemsTotal}");
        $this->line("Succeeded: {$result->itemsSucceeded}");
        $this->line("Skipped: {$result->itemsSkipped}");
        $this->line("Failed: {$result->itemsFailed}");

        foreach ($result->outcomes as $outcome) {
            if ($outcome->status === CatalogCandidateIngestionItemStatus::Succeeded) {
                continue;
            }

            $title = $outcome->title ?? '(untitled)';
            $this->line("- [{$outcome->index}] {$title}: {$outcome->status->value} — {$outcome->error}");
        }
    }
}
