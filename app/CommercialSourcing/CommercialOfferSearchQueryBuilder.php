<?php

namespace App\CommercialSourcing;

use App\Models\CatalogCandidate;

class CommercialOfferSearchQueryBuilder
{
    /**
     * @return list<string>
     */
    public function queries(CatalogCandidate $candidate, string $market): array
    {
        $limit = min(3, max(1, (int) config('commercial_sourcing.search.max_queries_per_candidate', 2)));
        $title = $this->collapseWhitespace((string) $candidate->title);
        $summary = $this->collapseWhitespace((string) ($candidate->summary ?? ''));
        $marketLabel = $this->marketLabel($market);

        $templates = [
            $this->compose($title, 'buy', $marketLabel),
            $this->compose($title, 'online', $marketLabel),
        ];

        if ($summary !== '') {
            $templates[] = $this->compose($title.' '.$this->truncate($summary, 80), 'buy', $marketLabel);
        }

        $queries = [];
        $seen = [];

        foreach ($templates as $query) {
            if (count($queries) >= $limit) {
                break;
            }

            if ($query === '' || isset($seen[$query])) {
                continue;
            }

            $seen[$query] = true;
            $queries[] = $query;
        }

        return $queries;
    }

    public function marketLabel(string $market): string
    {
        $market = strtoupper(trim($market));

        if ($market === '') {
            return '';
        }

        if (class_exists(\Locale::class)) {
            $region = trim((string) \Locale::getDisplayRegion('und_'.$market, 'en'));

            if ($region !== '' && strcasecmp($region, $market) !== 0) {
                return $region;
            }
        }

        return $market;
    }

    private function compose(string $title, string $intent, string $marketLabel): string
    {
        $parts = array_filter([$title, $intent, $marketLabel], fn (string $part): bool => $part !== '');
        $query = $this->collapseWhitespace(implode(' ', $parts));
        $maxLength = max(1, (int) config('commercial_sourcing.search.max_query_length', 400));

        if (mb_strlen($query) <= $maxLength) {
            return $query;
        }

        return rtrim(mb_substr($query, 0, $maxLength));
    }

    private function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $maxLength));
    }

    private function collapseWhitespace(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value));

        return is_string($collapsed) ? $collapsed : trim($value);
    }
}
