<?php

namespace App\CatalogCandidate\Discovery;

use App\Support\CatalogCandidateSourceUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class TavilyCatalogCandidateSearchProvider implements CatalogCandidateSearchProvider
{
    /**
     * Tavily Search `country` enum values (lowercase English names).
     *
     * @var list<string>
     */
    private const SUPPORTED_COUNTRIES = [
        'afghanistan', 'albania', 'algeria', 'andorra', 'angola', 'argentina', 'armenia',
        'australia', 'austria', 'azerbaijan', 'bahamas', 'bahrain', 'bangladesh', 'barbados',
        'belarus', 'belgium', 'belize', 'benin', 'bhutan', 'bolivia', 'bosnia and herzegovina',
        'botswana', 'brazil', 'brunei', 'bulgaria', 'burkina faso', 'burundi', 'cambodia',
        'cameroon', 'canada', 'cape verde', 'central african republic', 'chad', 'chile',
        'china', 'colombia', 'comoros', 'congo', 'costa rica', 'croatia', 'cuba', 'cyprus',
        'czech republic', 'denmark', 'djibouti', 'dominican republic', 'ecuador', 'egypt',
        'el salvador', 'equatorial guinea', 'eritrea', 'estonia', 'ethiopia', 'fiji',
        'finland', 'france', 'gabon', 'gambia', 'georgia', 'germany', 'ghana', 'greece',
        'guatemala', 'guinea', 'haiti', 'honduras', 'hungary', 'iceland', 'india',
        'indonesia', 'iran', 'iraq', 'ireland', 'israel', 'italy', 'jamaica', 'japan',
        'jordan', 'kazakhstan', 'kenya', 'kuwait', 'kyrgyzstan', 'latvia', 'lebanon',
        'lesotho', 'liberia', 'libya', 'liechtenstein', 'lithuania', 'luxembourg',
        'madagascar', 'malawi', 'malaysia', 'maldives', 'mali', 'malta', 'mauritania',
        'mauritius', 'mexico', 'moldova', 'monaco', 'mongolia', 'montenegro', 'morocco',
        'mozambique', 'myanmar', 'namibia', 'nepal', 'netherlands', 'new zealand',
        'nicaragua', 'niger', 'nigeria', 'north korea', 'north macedonia', 'norway',
        'oman', 'pakistan', 'panama', 'papua new guinea', 'paraguay', 'peru', 'philippines',
        'poland', 'portugal', 'qatar', 'romania', 'russia', 'rwanda', 'saudi arabia',
        'senegal', 'serbia', 'singapore', 'slovakia', 'slovenia', 'somalia', 'south africa',
        'south korea', 'south sudan', 'spain', 'sri lanka', 'sudan', 'sweden', 'switzerland',
        'syria', 'taiwan', 'tajikistan', 'tanzania', 'thailand', 'togo', 'trinidad and tobago',
        'tunisia', 'turkey', 'turkmenistan', 'uganda', 'ukraine', 'united arab emirates',
        'united kingdom', 'united states', 'uruguay', 'uzbekistan', 'venezuela', 'vietnam',
        'yemen', 'zambia', 'zimbabwe',
    ];

    public function __construct(
        private CatalogCandidateSearchQueryBuilder $queryBuilder,
    ) {}

    public function search(CatalogCandidateResearchBrief $brief): CatalogCandidateSearchResult
    {
        $apiKey = $this->apiKey();
        $queries = $this->queryBuilder->queries($brief);
        $startDate = now()->subDays($brief->freshnessDays)->toDateString();
        $country = $this->country($this->queryBuilder->marketLabel($brief->market));
        $corpus = [];
        $seen = [];
        $invalidResultCount = 0;
        $requestIds = [];
        $usage = [];

        foreach ($queries as $query) {
            $payload = $this->searchRequest($query, $apiKey, $startDate, $country);
            $decoded = $payload['json'];

            if (isset($decoded['request_id']) && is_string($decoded['request_id']) && $decoded['request_id'] !== '') {
                $requestIds[] = $decoded['request_id'];
            }

            if (isset($decoded['usage']) && is_array($decoded['usage'])) {
                $usage[] = $decoded['usage'];
            }

            $mapped = $this->mapResults($decoded['results']);
            $invalidResultCount += $mapped['invalid'];

            foreach ($mapped['sources'] as $source) {
                $key = $this->dedupeKey($source->url);

                if ($key === null || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $corpus[] = $source;
            }
        }

        return new CatalogCandidateSearchResult(
            corpus: $corpus,
            queries: $queries,
            metadata: [
                'provider' => 'tavily',
                'market' => $brief->market,
                'freshness_days' => $brief->freshnessDays,
                'start_date' => $startDate,
                'country' => $country,
                'invalid_result_count' => $invalidResultCount,
                'request_ids' => $requestIds,
                'usage' => $usage,
            ],
        );
    }

    /**
     * @return array{json: array<string, mixed>}
     */
    private function searchRequest(string $query, string $apiKey, string $startDate, ?string $country): array
    {
        $url = rtrim($this->baseUrl(), '/').'/search';
        $timeout = max(1, (int) config('catalog_candidate_discovery.search.providers.tavily.timeout', 20));
        $connectTimeout = max(1, (int) config('catalog_candidate_discovery.search.providers.tavily.connect_timeout', 5));
        $maxRedirects = max(0, (int) config('catalog_candidate_discovery.search.providers.tavily.max_redirects', 3));
        $maxResults = min(20, max(1, (int) config('catalog_candidate_discovery.search.max_results_per_query', 8)));

        $body = [
            'query' => $query,
            'search_depth' => 'basic',
            'max_results' => $maxResults,
            'topic' => 'general',
            'include_answer' => false,
            'include_raw_content' => false,
            'include_images' => false,
            'auto_parameters' => false,
            'start_date' => $startDate,
        ];

        if ($country !== null) {
            $body['country'] = $country;
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->withOptions([
                    'allow_redirects' => ['max' => $maxRedirects],
                ])
                ->post($url, $body);
        } catch (ConnectionException $exception) {
            throw new CatalogCandidateSearchException(
                'The Tavily search request timed out or could not connect.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            throw new CatalogCandidateSearchException(
                'The Tavily search request failed.',
                previous: $exception,
            );
        }

        $this->assertSuccessful($response);

        $decoded = $response->json();

        if (! is_array($decoded) || ! array_key_exists('results', $decoded)) {
            throw new CatalogCandidateSearchException('The Tavily search response was malformed.');
        }

        if (! is_array($decoded['results'])) {
            throw new CatalogCandidateSearchException('The Tavily search response was malformed.');
        }

        return ['json' => $decoded];
    }

    private function assertSuccessful(Response $response): void
    {
        $status = $response->status();

        if ($status === 200) {
            $decoded = $response->json();

            if (! is_array($decoded) && $response->body() !== '') {
                throw new CatalogCandidateSearchException('The Tavily search response was malformed.');
            }

            return;
        }

        $detail = $this->errorDetail($response);

        $message = match (true) {
            $status === 401 => 'Tavily search is unauthorized. Check TAVILY_API_KEY.',
            $status === 403 => 'Tavily search is forbidden.',
            $status === 429 => 'Tavily search is rate limited.',
            in_array($status, [432, 433], true) => 'Tavily search exceeded the plan or usage limit.',
            $status >= 500 => 'Tavily search failed due to a server error.',
            default => "Tavily search failed with HTTP {$status}.",
        };

        if ($detail !== null) {
            $message .= ' '.$detail;
        }

        throw new CatalogCandidateSearchException($message);
    }

    private function errorDetail(Response $response): ?string
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            return null;
        }

        $detail = $decoded['detail'] ?? null;

        if (is_array($detail) && isset($detail['error']) && is_string($detail['error']) && $detail['error'] !== '') {
            return $detail['error'];
        }

        if (is_string($detail) && $detail !== '') {
            return $detail;
        }

        return null;
    }

    /**
     * @return array{sources: list<RetrievedCatalogCandidateSource>, invalid: int}
     */
    private function mapResults(mixed $results): array
    {
        if (! is_array($results)) {
            throw new CatalogCandidateSearchException('The Tavily search response was malformed.');
        }

        $sources = [];
        $invalid = 0;
        $snippetMax = max(1, (int) config('catalog_candidate_discovery.search.snippet_max_length', 400));

        foreach ($results as $item) {
            if (! is_array($item) || array_is_list($item)) {
                $invalid++;

                continue;
            }

            $url = CatalogCandidateSourceUrl::normalize($item['url'] ?? null);

            if ($url === null) {
                $invalid++;

                continue;
            }

            $host = parse_url($url, PHP_URL_HOST);

            if (! is_string($host) || $host === '') {
                $invalid++;

                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            $snippet = $this->truncateSnippet(trim((string) ($item['content'] ?? '')), $snippetMax);

            $sources[] = new RetrievedCatalogCandidateSource(
                url: $url,
                title: $title !== '' ? $title : CatalogCandidateSourceUrl::sourceName($host),
                snippet: $snippet,
                sourceName: CatalogCandidateSourceUrl::sourceName($host),
                retrievedAt: now(),
            );
        }

        return ['sources' => $sources, 'invalid' => $invalid];
    }

    private function dedupeKey(string $url): ?string
    {
        return CatalogCandidateSourceUrl::key($url);
    }

    private function truncateSnippet(string $snippet, int $maxLength): string
    {
        if (mb_strlen($snippet) <= $maxLength) {
            return $snippet;
        }

        return mb_substr($snippet, 0, $maxLength);
    }

    private function country(string $marketLabel): ?string
    {
        $country = strtolower(trim($marketLabel));

        if ($country === '' || ! in_array($country, self::SUPPORTED_COUNTRIES, true)) {
            return null;
        }

        return $country;
    }

    private function apiKey(): string
    {
        $apiKey = config('catalog_candidate_discovery.search.providers.tavily.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new InvalidArgumentException('TAVILY_API_KEY is not configured.');
        }

        return trim($apiKey);
    }

    private function baseUrl(): string
    {
        $baseUrl = config('catalog_candidate_discovery.search.providers.tavily.base_url', 'https://api.tavily.com');

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw new InvalidArgumentException('The Tavily search base URL is not configured.');
        }

        return trim($baseUrl);
    }
}
