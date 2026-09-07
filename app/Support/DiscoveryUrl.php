<?php

namespace App\Support;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class DiscoveryUrl
{
    public static function route(string $name, array $parameters = [], bool $absolute = false): string
    {
        $template = Arr::get(config('discovery.routes', []), $name);

        if (! is_string($template)) {
            throw new InvalidArgumentException("Discovery route [{$name}] is not configured.");
        }

        $path = $template;

        foreach ($parameters as $key => $value) {
            $path = str_replace('{'.$key.'}', $value, $path);
        }

        if (preg_match('/\{[a-z_]+\}/', $path) === 1) {
            throw new InvalidArgumentException("Discovery route [{$name}] has unresolved placeholders.");
        }

        if ($absolute) {
            return rtrim((string) config('app.url'), '/').$path;
        }

        return $path;
    }

    public static function gift(string $slug, bool $absolute = false, ?string $context = null): string
    {
        $url = self::route('gift.show', ['slug' => $slug], $absolute);

        if (! is_string($context) || $context === '') {
            return $url;
        }

        return $url.'?'.http_build_query(['context' => $context], '', '&', PHP_QUERY_RFC3986);
    }

    public static function giftIdeas(bool $absolute = false): string
    {
        return self::route('gift_ideas.index', absolute: $absolute);
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    public static function giftIdeasQuery(array $query = [], bool $absolute = false): string
    {
        $url = self::giftIdeas($absolute);

        $query = collect($query)
            ->reject(fn ($value) => $value === null || $value === '')
            ->map(fn ($value) => (string) $value)
            ->all();

        if ($query === []) {
            return $url;
        }

        return $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public static function giftIdeasCategory(string $fullPath, bool $absolute = false): string
    {
        return self::route('gift_ideas.category', ['full_path' => $fullPath], $absolute);
    }

    public static function occasion(string $slug, bool $absolute = false): string
    {
        return self::route('occasion.show', ['slug' => $slug], $absolute);
    }

    public static function relationship(string $slug, bool $absolute = false): string
    {
        return self::route('relationship.show', ['slug' => $slug], $absolute);
    }

    public static function recipientType(string $slug, bool $absolute = false): string
    {
        return self::route('recipient_type.show', ['slug' => $slug], $absolute);
    }

    public static function interest(string $slug, bool $absolute = false): string
    {
        return self::route('interest.show', ['slug' => $slug], $absolute);
    }

    public static function profession(string $slug, bool $absolute = false): string
    {
        return self::route('profession.show', ['slug' => $slug], $absolute);
    }

    public static function giftType(string $slug, bool $absolute = false): string
    {
        return self::route('gift_type.show', ['slug' => $slug], $absolute);
    }

    public static function finder(bool $absolute = false): string
    {
        return self::route('finder.show', absolute: $absolute);
    }

    public static function finderEdit(string $uuid, bool $absolute = false): string
    {
        return self::finder($absolute).'?'.http_build_query(['session' => $uuid], '', '&', PHP_QUERY_RFC3986);
    }

    public static function finderResults(string $uuid, bool $absolute = false): string
    {
        return self::route('finder.results', ['uuid' => $uuid], $absolute);
    }

    public static function affiliateOut(string $uuid, bool $absolute = false): string
    {
        return self::route('affiliate.out', ['uuid' => $uuid], $absolute);
    }

    public static function seoLandingPage(string $slug, bool $absolute = false): string
    {
        return self::route('seo_landing.show', ['slug' => $slug], $absolute);
    }

    public static function sitemap(bool $absolute = false): string
    {
        return self::route('sitemap.index', absolute: $absolute);
    }
}
