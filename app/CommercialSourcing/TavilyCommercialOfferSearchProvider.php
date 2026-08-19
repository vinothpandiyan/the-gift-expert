<?php

namespace App\CommercialSourcing;

use App\Models\CatalogCandidate;
use App\Support\CatalogCandidateSourceUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class TavilyCommercialOfferSearchProvider implements CommercialOfferSearchProvider
{
    /**
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
        private CommercialOfferSearchQueryBuilder $queryBuilder,
        private CommercialSourcingMerchants $merchants,
    ) {}

    public function search(CatalogCandidate $candidate, string $market): CommercialOfferSearchResult
    {
        $domains = $this->merchants->includeDomains($market);
        $queries = $this->queryBuilder->queries($candidate, $market);
        $country = $this->country($this->queryBuilder->marketLabel($market));

        if ($domains === [] || $queries === []) {
            return new CommercialOfferSearchResult(
                hits: [],
                queries: $queries,
                metadata: [
                    'provider' => 'tavily',
                    'market' => strtoupper(trim($market)),
                    'include_domains' => $domains,
                    'country' => $country,
                    'invalid_result_count' => 0,
                    'discarded_off_allowlist' => 0,
                ],
            );
        }

        $apiKey = $this->apiKey();
        $hits = [];
        $seen = [];
        $invalidResultCount = 0;
        $discardedOffAllowlist = 0;
        $requestIds = [];

        foreach ($queries as $query) {
            $decoded = $this->searchRequest($query, $apiKey, $country, $domains);

            if (isset($decoded['request_id']) && is_string($decoded['request_id']) && $decoded['request_id'] !== '') {
                $requestIds[] = $decoded['request_id'];
            }

            $mapped = $this->mapResults($decoded['results'] ?? null, $domains);
            $invalidResultCount += $mapped['invalid'];
            $discardedOffAllowlist += $mapped['discarded'];

            foreach ($mapped['hits'] as $hit) {
                $key = CatalogCandidateSourceUrl::key($hit->url);

                if ($key === null || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $hits[] = $hit;
            }
        }

        return new CommercialOfferSearchResult(
            hits: $hits,
            queries: $queries,
            metadata: [
                'provider' => 'tavily',
                'market' => strtoupper(trim($market)),
                'include_domains' => $domains,
                'country' => $country,
                'invalid_result_count' => $invalidResultCount,
                'discarded_off_allowlist' => $discardedOffAllowlist,
                'request_ids' => $requestIds,
            ],
        );
    }

    /**
     * @param  list<string>  $domains
     * @return array<string, mixed>
     */
    private function searchRequest(string $query, string $apiKey, ?string $country, array $domains): array
    {
        $url = rtrim($this->baseUrl(), '/').'/search';
        $timeout = max(1, (int) config('commercial_sourcing.search.providers.tavily.timeout', 20));
        $connectTimeout = max(1, (int) config('commercial_sourcing.search.providers.tavily.connect_timeout', 5));
        $maxRedirects = max(0, (int) config('commercial_sourcing.search.providers.tavily.max_redirects', 3));
        $maxResults = min(20, max(1, (int) config('commercial_sourcing.search.max_results_per_query', 8)));

        $body = [
            'query' => $query,
            'search_depth' => 'basic',
            'max_results' => $maxResults,
            'topic' => 'general',
            'include_answer' => false,
            'include_raw_content' => false,
            'include_images' => false,
            'auto_parameters' => false,
            'include_domains' => $domains,
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
            throw new CommercialOfferSearchException(
                'The Tavily commercial search request timed out or could not connect.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            throw new CommercialOfferSearchException(
                'The Tavily commercial search request failed.',
                previous: $exception,
            );
        }

        $this->assertSuccessful($response);

        $decoded = $response->json();

        if (! is_array($decoded) || ! array_key_exists('results', $decoded) || ! is_array($decoded['results'])) {
            throw new CommercialOfferSearchException('The Tavily commercial search response was malformed.');
        }

        return $decoded;
    }

    private function assertSuccessful(Response $response): void
    {
        $status = $response->status();

        if ($status === 200) {
            $decoded = $response->json();

            if (! is_array($decoded) && $response->body() !== '') {
                throw new CommercialOfferSearchException('The Tavily commercial search response was malformed.');
            }

            return;
        }

        $detail = $this->errorDetail($response);

        $message = match (true) {
            $status === 401 => 'Tavily commercial search is unauthorized. Check TAVILY_API_KEY.',
            $status === 403 => 'Tavily commercial search is forbidden.',
            $status === 429 => 'Tavily commercial search is rate limited.',
            in_array($status, [432, 433], true) => 'Tavily commercial search exceeded the plan or usage limit.',
            $status >= 500 => 'Tavily commercial search failed due to a server error.',
            default => "Tavily commercial search failed with HTTP {$status}.",
        };

        if ($detail !== null) {
            $message .= ' '.$detail;
        }

        throw new CommercialOfferSearchException($message);
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
     * @param  list<string>  $domains
     * @return array{hits: list<CommercialSearchHit>, invalid: int, discarded: int}
     */
    private function mapResults(mixed $results, array $domains): array
    {
        if (! is_array($results)) {
            throw new CommercialOfferSearchException('The Tavily commercial search response was malformed.');
        }

        $hits = [];
        $invalid = 0;
        $discarded = 0;
        $snippetMax = max(1, (int) config('commercial_sourcing.search.snippet_max_length', 400));

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

            if (! $this->hostAllowed($host, $domains)) {
                $discarded++;

                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            $snippet = $this->truncateSnippet(trim((string) ($item['content'] ?? '')), $snippetMax);
            $imageUrls = $this->imageUrls($item);

            $hits[] = new CommercialSearchHit(
                url: $url,
                title: $title !== '' ? $title : CatalogCandidateSourceUrl::sourceName($host),
                snippet: $snippet,
                imageUrls: $imageUrls,
                retrievedAt: now(),
            );
        }

        return ['hits' => $hits, 'invalid' => $invalid, 'discarded' => $discarded];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function imageUrls(array $item): array
    {
        $urls = [];

        foreach (['image', 'images'] as $key) {
            $value = $item[$key] ?? null;

            if (is_string($value) && CatalogCandidateSourceUrl::normalize($value) !== null) {
                $urls[] = $value;
            }

            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $image) {
                if (is_string($image) && CatalogCandidateSourceUrl::normalize($image) !== null) {
                    $urls[] = $image;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param  list<string>  $domains
     */
    private function hostAllowed(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            if ($this->merchants->hostMatchesDomain($host, $domain)) {
                return true;
            }
        }

        return false;
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
        $apiKey = config('commercial_sourcing.search.providers.tavily.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new InvalidArgumentException('TAVILY_API_KEY is not configured.');
        }

        return trim($apiKey);
    }

    private function baseUrl(): string
    {
        $baseUrl = config('commercial_sourcing.search.providers.tavily.base_url', 'https://api.tavily.com');

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw new InvalidArgumentException('The Tavily commercial search base URL is not configured.');
        }

        return trim($baseUrl);
    }
}
