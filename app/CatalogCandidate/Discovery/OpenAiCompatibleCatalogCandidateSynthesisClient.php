<?php

namespace App\CatalogCandidate\Discovery;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class OpenAiCompatibleCatalogCandidateSynthesisClient
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function complete(string $system, string $user, array $schema): array
    {
        $apiKey = $this->apiKey();
        $model = $this->model();
        $url = rtrim($this->baseUrl(), '/').'/chat/completions';
        $timeout = max(1, (int) config('catalog_candidate_discovery.synthesis.timeout', 45));
        $connectTimeout = max(1, (int) config('catalog_candidate_discovery.synthesis.connect_timeout', 5));
        $maxRedirects = max(0, (int) config('catalog_candidate_discovery.synthesis.max_redirects', 3));
        $maxOutputTokens = max(1, (int) config('catalog_candidate_discovery.synthesis.max_output_tokens', 4000));

        $body = [
            'model' => $model,
            'max_completion_tokens' => $maxOutputTokens,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'catalog_candidate_synthesis',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ];

        $temperature = config('catalog_candidate_discovery.synthesis.temperature');

        if ($temperature !== null && $temperature !== '') {
            $body['temperature'] = (float) $temperature;
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
            throw new CatalogCandidateSynthesisException(
                'The catalog candidate synthesis request timed out or could not connect.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            throw new CatalogCandidateSynthesisException(
                'The catalog candidate synthesis request failed.',
                previous: $exception,
            );
        }

        $this->assertSuccessful($response);

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new CatalogCandidateSynthesisException('The catalog candidate synthesis response was malformed.');
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new CatalogCandidateSynthesisException('The catalog candidate synthesis response was malformed.');
        }

        $payload = json_decode($content, true);

        if (! is_array($payload) || ! array_key_exists('candidates', $payload) || ! is_array($payload['candidates']) || ! array_is_list($payload['candidates'])) {
            throw new CatalogCandidateSynthesisException('The catalog candidate synthesis response was malformed.');
        }

        return $payload;
    }

    private function assertSuccessful(Response $response): void
    {
        $status = $response->status();

        if ($status === 200) {
            $decoded = $response->json();

            if (! is_array($decoded) && $response->body() !== '') {
                throw new CatalogCandidateSynthesisException('The catalog candidate synthesis response was malformed.');
            }

            return;
        }

        $detail = $this->errorDetail($response);

        $message = match (true) {
            $status === 401 => 'Catalog candidate synthesis is unauthorized. Check OPENAI_API_KEY.',
            $status === 403 => 'Catalog candidate synthesis is forbidden.',
            $status === 429 => 'Catalog candidate synthesis is rate limited.',
            $status >= 500 => 'Catalog candidate synthesis failed due to a server error.',
            default => "Catalog candidate synthesis failed with HTTP {$status}.",
        };

        if ($detail !== null) {
            $message .= ' '.$detail;
        }

        throw new CatalogCandidateSynthesisException($message);
    }

    private function errorDetail(Response $response): ?string
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            return null;
        }

        $error = $decoded['error'] ?? null;

        if (is_array($error) && isset($error['message']) && is_string($error['message']) && $error['message'] !== '') {
            return $error['message'];
        }

        if (is_string($error) && $error !== '') {
            return $error;
        }

        return null;
    }

    private function apiKey(): string
    {
        $apiKey = config('catalog_candidate_discovery.synthesis.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new InvalidArgumentException('OPENAI_API_KEY is not configured.');
        }

        return trim($apiKey);
    }

    private function model(): string
    {
        $model = config('catalog_candidate_discovery.synthesis.model');

        if (! is_string($model) || trim($model) === '') {
            throw new InvalidArgumentException('OPENAI_MODEL is not configured.');
        }

        return trim($model);
    }

    private function baseUrl(): string
    {
        $baseUrl = config('catalog_candidate_discovery.synthesis.base_url', 'https://api.openai.com/v1');

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw new InvalidArgumentException('The catalog candidate synthesis base URL is not configured.');
        }

        return trim($baseUrl);
    }
}
