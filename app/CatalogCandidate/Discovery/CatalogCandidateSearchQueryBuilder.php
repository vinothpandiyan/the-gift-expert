<?php

namespace App\CatalogCandidate\Discovery;

class CatalogCandidateSearchQueryBuilder
{
    public const MAX_QUERY_LENGTH = 400;

    /**
     * @return list<string>
     */
    public function queries(CatalogCandidateResearchBrief $brief): array
    {
        $limit = min(3, max(1, (int) config('catalog_candidate_discovery.search.max_queries_per_brief', 3)));
        $marketLabel = $this->marketLabel($brief->market);
        $includeMarket = $marketLabel !== '' && ! $this->containsLabel($brief->brief, $marketLabel);
        $categoryHints = $this->categoryHints($brief->sourceCategories);

        $templates = [
            $this->compose($brief->brief, modifier: null, marketLabel: $includeMarket ? $marketLabel : null, hints: $categoryHints),
            $this->compose($brief->brief, modifier: 'trending', marketLabel: $includeMarket ? $marketLabel : null, hints: $categoryHints),
            $this->compose($brief->brief, modifier: 'unique', marketLabel: $includeMarket ? $marketLabel : null, hints: $categoryHints),
        ];

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

    /**
     * @param  list<string>  $sourceCategories
     */
    private function categoryHints(array $sourceCategories): string
    {
        $hints = [];

        foreach ($sourceCategories as $category) {
            $category = $this->collapseWhitespace((string) $category);

            if ($category === '') {
                continue;
            }

            $hints[] = $category;
        }

        return implode(' ', $hints);
    }

    private function compose(string $brief, ?string $modifier, ?string $marketLabel, string $hints): string
    {
        $parts = [];
        $strippedImperative = $this->stripLeadingImperative($brief) !== $brief;
        $intent = $this->intentPhrase($brief);

        if ($modifier !== null && $modifier !== '' && $strippedImperative && $intent !== '') {
            $parts[] = $modifier;
            $parts[] = $intent;
        } else {
            $parts[] = $brief;

            if ($modifier !== null && $modifier !== '') {
                $parts[] = $modifier;
            }
        }

        if ($marketLabel !== null && $marketLabel !== '') {
            $parts[] = $marketLabel;
        }

        if ($hints !== '') {
            $parts[] = $hints;
        }

        $query = $this->collapseWhitespace(implode(' ', $parts));
        $maxLength = max(1, (int) config(
            'catalog_candidate_discovery.search.max_query_length',
            self::MAX_QUERY_LENGTH,
        ));

        if (mb_strlen($query) <= $maxLength) {
            return $query;
        }

        return rtrim(mb_substr($query, 0, $maxLength));
    }

    private function intentPhrase(string $brief): string
    {
        $stripped = $this->stripLeadingImperative($brief);
        $withoutFiller = preg_replace('/^(useful|good|great|best)\s+/iu', '', $stripped);

        if (! is_string($withoutFiller)) {
            return $stripped;
        }

        $withoutFiller = trim($withoutFiller);

        return $withoutFiller !== '' ? $withoutFiller : $stripped;
    }

    private function stripLeadingImperative(string $brief): string
    {
        $stripped = preg_replace('/^(find|looking for|search for)\s+/iu', '', $brief);

        if (! is_string($stripped)) {
            return $brief;
        }

        $stripped = trim($stripped);

        return $stripped !== '' ? $stripped : $brief;
    }

    private function containsLabel(string $haystack, string $label): bool
    {
        return mb_stripos($haystack, $label) !== false;
    }

    private function collapseWhitespace(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value));

        return is_string($collapsed) ? $collapsed : trim($value);
    }
}
