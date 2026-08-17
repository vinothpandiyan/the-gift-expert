<?php

namespace Tests\Unit\Actions\Import;

use App\Import\ProviderImagePolicy;
use Tests\TestCase;

class ProviderImagePolicyTest extends TestCase
{
    public function test_fake_provider_allows_local_acquisition(): void
    {
        $policy = ProviderImagePolicy::forKey('fake');

        $this->assertTrue($policy->storeImages);
        $this->assertTrue($policy->transformImages);
        $this->assertSame(5, $policy->maxImages);
        $this->assertTrue($policy->allowsLocalAcquisition());
    }

    public function test_amazon_associates_does_not_allow_local_acquisition(): void
    {
        $policy = ProviderImagePolicy::forKey('amazon_associates');

        $this->assertFalse($policy->storeImages);
        $this->assertFalse($policy->transformImages);
        $this->assertFalse($policy->allowsLocalAcquisition());
    }

    public function test_unknown_provider_is_not_allowed(): void
    {
        $policy = ProviderImagePolicy::forKey('not_configured');

        $this->assertFalse($policy->allowsLocalAcquisition());
        $this->assertSame(5, $policy->maxImages);
    }

    public function test_either_flag_false_disallows_acquisition(): void
    {
        config()->set('import.providers.fake.policy.store_images', true);
        config()->set('import.providers.fake.policy.transform_images', false);

        $this->assertFalse(ProviderImagePolicy::forKey('fake')->allowsLocalAcquisition());
    }
}
