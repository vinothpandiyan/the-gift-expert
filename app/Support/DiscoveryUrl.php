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

    public static function gift(string $slug, bool $absolute = false): string
    {
        return self::route('gift.show', ['slug' => $slug], $absolute);
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

    public static function finderResults(string $uuid, bool $absolute = false): string
    {
        return self::route('finder.results', ['uuid' => $uuid], $absolute);
    }

    public static function affiliateOut(string $uuid, bool $absolute = false): string
    {
        return self::route('affiliate.out', ['uuid' => $uuid], $absolute);
    }
}
