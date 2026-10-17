<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\ConfiguresRecipientEligibility;

abstract class TestCase extends BaseTestCase
{
    use ConfiguresRecipientEligibility;

    protected function setUp(): void
    {
        parent::setUp();

        // Every test starts in strict recipient-eligibility mode (the shipped
        // defaults) regardless of the developer's .env or shell. Tests that need
        // inference call enableRecipientEligibility() explicitly.
        $this->useStrictRecipientEligibility();
    }
}
