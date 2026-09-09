<?php

namespace App\CuratedCatalog;

class CuratedEditorialCopyPrompt
{
    /**
     * @return array{system: string, user: string, schema: array<string, mixed>}
     */
    public function messages(
        string $sourceTitle,
        string $merchantName,
        ?string $currentName,
        ?string $currentShortDescription,
        ?string $currentDescription,
    ): array {
        return [
            'system' => $this->systemInstructions(),
            'user' => $this->userPayload(
                $sourceTitle,
                $merchantName,
                $currentName,
                $currentShortDescription,
                $currentDescription,
            ),
            'schema' => $this->jsonSchema(),
        ];
    }

    public function systemInstructions(): string
    {
        return <<<'PROMPT'
You rewrite Gift Expert editorial copy for one already-curated merchant product. You do not classify taxonomy. You do not change price, availability, merchant identity, or source title.

The merchant source title is marketplace truth and must be left unchanged in your reasoning. You only produce Gift Expert editorial fields.

Copy contract:
- name: concise Gift Expert catalog title. Omit marketplace seller/brand prefixes when they add no meaningful product identity. Retain a brand only when that brand is itself part of how shoppers recognize the product. Strip marketplace keyword stuffing, repeated recipient/occasion phrases, pack/variant noise, and "Best Gift for..." patterns. Keep the actual product identity. Never invent attributes. Do not remove the first word merely because it looks like a brand.
- short_description: what the item is; factual; about 100–180 characters when practical; no invented claims.
- description: why it is a great gift. Write 2–4 concise reasons, normally 3, each on its own line. No paragraph. No numbering, bullets, or leading checkmarks. Each line must be a distinct reason. Prioritize emotional or personal value, usefulness, or distinctive gift qualities. Do not repeat taxonomy labels verbatim. Avoid generic filler such as "Makes a wonderful gift". Do not make unsupported product claims.

Example:
SOURCE: Giftplease Personalized Best Friend Acrylic Night Light - Custom Photo Friendship Lamp with Wooden Base, Personalized for Besties, Birthday, Friendship, Moving Away, Christmas Graduation
EDITORIAL TITLE: Personalized Best Friend Acrylic Night Light
SHORT DESCRIPTION: Personalized acrylic night light with a custom photo and wooden base, designed as a keepsake for a best friend.
DESCRIPTION:
Turns a favorite photo into a lasting keepsake
Personal and meaningful without feeling generic
Great for birthdays, farewells and graduation

Never invent materials, quantities, warranty, technical performance, ratings, review counts, or features unless explicitly present in the supplied title or current copy.

Output structured JSON only. No markdown.
PROMPT;
    }

    public function userPayload(
        string $sourceTitle,
        string $merchantName,
        ?string $currentName,
        ?string $currentShortDescription,
        ?string $currentDescription,
    ): string {
        $payload = [
            'source_title' => $sourceTitle,
            'merchant_name' => $merchantName,
            'current_name' => $currentName,
            'current_short_description' => $currentShortDescription,
            'current_description' => $currentDescription,
            'note' => 'Rewrite editorial copy only. Do not echo or alter the source title. Do not output taxonomy.',
        ];

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Concise Gift Expert catalog title without marketplace seller noise.',
                ],
                'short_description' => [
                    'type' => ['string', 'null'],
                    'description' => 'Factual one-line description of what the item is.',
                ],
                'description' => [
                    'type' => ['string', 'null'],
                    'description' => '2–4 newline-separated reasons, normally 3, explaining why it is a great gift.',
                ],
            ],
            'required' => ['name', 'short_description', 'description'],
        ];
    }
}
