<?php

namespace App\Actions\Navigation;

use App\Enums\NavigationLinkType;
use Illuminate\Support\Arr;

class ValidateNavigationTargetAction
{
    /**
     * @return array<string, string>
     */
    public function execute(
        ?NavigationLinkType $linkType,
        ?int $linkableId = null,
        ?string $routeKey = null,
        ?string $url = null,
        bool $required = true,
    ): array {
        if ($linkType === null) {
            return $required ? ['link_type' => 'A link type is required.'] : [];
        }

        $errors = match ($linkType) {
            NavigationLinkType::Relationship,
            NavigationLinkType::Occasion,
            NavigationLinkType::Interest,
            NavigationLinkType::Profession,
            NavigationLinkType::RecipientType,
            NavigationLinkType::GiftType,
            NavigationLinkType::Category,
            NavigationLinkType::SeoLandingPage => $linkableId === null || $linkableId < 1
                ? ['linkable_id' => 'Select a target.']
                : [],
            NavigationLinkType::DiscoveryRoute => filled($routeKey)
                ? []
                : ['route_key' => 'Select a discovery route.'],
            NavigationLinkType::ExternalUrl => filled($url)
                ? []
                : ['url' => 'Enter an absolute http or https URL.'],
        };

        if ($errors !== []) {
            return $errors;
        }

        if (app(ResolveNavigationHrefAction::class)->execute($linkType, $linkableId, $routeKey, $url) !== null) {
            return [];
        }

        return match ($linkType) {
            NavigationLinkType::DiscoveryRoute => ['route_key' => 'This discovery route cannot be used in navigation.'],
            NavigationLinkType::ExternalUrl => ['url' => 'Enter an absolute http or https URL.'],
            NavigationLinkType::SeoLandingPage => ['linkable_id' => 'Only published, indexable landing pages can be linked.'],
            default => ['linkable_id' => 'This target is not available for navigation.'],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sanitize(array $data): array
    {
        $type = $data['link_type'] ?? null;

        if (is_string($type)) {
            $type = NavigationLinkType::tryFrom($type);
        }

        if (! $type instanceof NavigationLinkType) {
            $data['link_type'] = null;
            $data['linkable_id'] = null;
            $data['route_key'] = null;
            $data['url'] = null;

            return $data;
        }

        $data['link_type'] = $type->value;

        return match ($type) {
            NavigationLinkType::DiscoveryRoute => [
                ...$data,
                'linkable_id' => null,
                'url' => null,
            ],
            NavigationLinkType::ExternalUrl => [
                ...$data,
                'linkable_id' => null,
                'route_key' => null,
            ],
            default => [
                ...$data,
                'route_key' => null,
                'url' => null,
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    public static function selectableDiscoveryRoutes(): array
    {
        $options = [];

        foreach (Arr::wrap(config('discovery.routes', [])) as $key => $template) {
            if (! is_string($key) || ! is_string($template) || $template === '') {
                continue;
            }

            if (preg_match('/\{[a-z_]+\}/', $template) === 1) {
                continue;
            }

            $options[$key] = $key.' ('.$template.')';
        }

        return $options;
    }
}
