<?php

namespace Tests\Unit\CuratedCatalog;

use App\CuratedCatalog\CuratedEditorialCopyPrompt;
use Tests\TestCase;

class CuratedEditorialCopyPromptTest extends TestCase
{
    public function test_prompt_encodes_title_cleaning_and_scannable_why_contract(): void
    {
        $prompt = app(CuratedEditorialCopyPrompt::class);
        $system = $prompt->systemInstructions();
        $schema = json_encode($prompt->jsonSchema());

        $this->assertStringContainsString('Omit marketplace seller/brand prefixes', $system);
        $this->assertStringContainsString('Do not remove the first word merely because it looks like a brand', $system);
        $this->assertStringContainsString('Giftplease Personalized Best Friend Acrylic Night Light', $system);
        $this->assertStringContainsString('Personalized Best Friend Acrylic Night Light', $system);
        $this->assertStringContainsString('normally 3', $system);
        $this->assertStringContainsString('You do not classify taxonomy', $system);
        $this->assertStringNotContainsString('affiliate', (string) $schema);
        $this->assertStringNotContainsString('taxonomy', (string) $schema);
    }

    public function test_user_payload_keeps_source_title_separate_from_editorial_name(): void
    {
        $user = app(CuratedEditorialCopyPrompt::class)->userPayload(
            'Giftplease Personalized Best Friend Acrylic Night Light - Custom Photo',
            'Amazon India',
            'Giftplease Personalized Best Friend Acrylic Night Light',
            'A lamp.',
            'A paragraph.',
        );
        $decoded = json_decode($user, true);

        $this->assertSame(
            'Giftplease Personalized Best Friend Acrylic Night Light - Custom Photo',
            $decoded['source_title'],
        );
        $this->assertSame('Giftplease Personalized Best Friend Acrylic Night Light', $decoded['current_name']);
        $this->assertStringContainsString('Do not echo or alter the source title', $decoded['note']);
    }
}
