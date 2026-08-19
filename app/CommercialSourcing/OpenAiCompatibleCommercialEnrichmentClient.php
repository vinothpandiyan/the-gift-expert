<?php

namespace App\CommercialSourcing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class OpenAiCompatibleCommercialEnrichmentClient
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
        $timeout = max(1, (int) config('commercial_sourcing.enrichment.timeout', 45));
        $connectTimeout = max(1, (int) config('commercial_sourcing.enrichment.connect_timeout', 5));
        $maxRedirects = max(0, (int) config('commercial_sourcing.enrichment.max_redirects', 3));
        $maxOutputTokens = max(1, (int) config('commercial_sourcing.enrichment.max_output_tokens', 2000));

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
                    'name' => 'commercial_offer_enrichment',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ];

        $temperature = config('commercial_sourcing.enrichment.temperature');

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
            throw new CommercialEnrichmentException(
                'The commercial enrichment request timed out or could not connect.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            throw new CommercialEnrichmentException(
                'The commercial enrichment request failed.',
                previous: $exception,
            );
        }

        $this->assertSuccessful($response);

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new CommercialEnrichmentException('The commercial enrichment response was malformed.');
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new CommercialEnrichmentException('The commercial enrichment response was malformed.');
        }

        $payload = json_decode($content, true);

        if (! is_array($payload) || array_is_list($payload) || ! isset($payload['taxonomy']) || ! is_array($payload['taxonomy'])) {
            throw new CommercialEnrichmentException('The commercial enrichment response was malformed.');
        }

        if (! array_key_exists('name', $payload) || ! is_string($payload['name'])) {
            throw new CommercialEnrichmentException('The commercial enrichment response was malformed.');
        }

        return $payload;
    }

    private function assertSuccessful(Response $response): void
    {
        $status = $response->status();

        if ($status === 200) {
            $decoded = $response->json();

            if (! is_array($decoded) && $response->body() !== '') {
                throw new CommercialEnrichmentException('The commercial enrichment response was malformed.');
            }

            return;
        }

        $detail = $this->errorDetail($response);

        $message = match (true) {
            $status === 401 => 'Commercial enrichment is unauthorized. Check OPENAI_API_KEY.',
            $status === 403 => 'Commercial enrichment is forbidden.',
            $status === 429 => 'Commercial enrichment is rate limited.',
            $status >= 500 => 'Commercial enrichment failed due to a server error.',
            default => "Commercial enrichment failed with HTTP {$status}.",
        };

        if ($detail !== null) {
            $message .= ' '.$detail;
        }

        throw new CommercialEnrichmentException($message);
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
        $apiKey = config('commercial_sourcing.enrichment.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new InvalidArgumentException('OPENAI_API_KEY is not configured.');
        }

        return trim($apiKey);
    }

    private function model(): string
    {
        $model = config('commercial_sourcing.enrichment.model');

        if (! is_string($model) || trim($model) === '') {
            throw new InvalidArgumentException('OPENAI_MODEL is not configured.');
        }

        return trim($model);
    }

    private function baseUrl(): string
    {
        $baseUrl = config('commercial_sourcing.enrichment.base_url', 'https://api.openai.com/v1');

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw new InvalidArgumentException('The commercial enrichment base URL is not configured.');
        }

        return trim($baseUrl);
    }
}
