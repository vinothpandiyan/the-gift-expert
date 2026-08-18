<?php

namespace Tests\Unit\Support;

use App\Support\CatalogCandidateTitleFingerprint;
use Tests\TestCase;

class CatalogCandidateTitleFingerprintTest extends TestCase
{
    public function test_identical_normalized_titles_produce_the_same_fingerprint(): void
    {
        $expected = CatalogCandidateTitleFingerprint::from('Portable Photo Printer');

        $this->assertSame($expected, CatalogCandidateTitleFingerprint::from(' portable   photo printer '));
        $this->assertSame($expected, CatalogCandidateTitleFingerprint::from('Portable Photo-Printer'));
        $this->assertSame($expected, CatalogCandidateTitleFingerprint::from('PORTABLE PHOTO PRINTER'));
    }

    public function test_fingerprints_are_sha_256_hex(): void
    {
        $fingerprint = CatalogCandidateTitleFingerprint::from('Portable Photo Printer');

        $this->assertSame(64, strlen($fingerprint));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fingerprint);
        $this->assertSame(hash('sha256', 'portable photo printer'), $fingerprint);
    }

    public function test_different_titles_normally_produce_different_fingerprints(): void
    {
        $this->assertNotSame(
            CatalogCandidateTitleFingerprint::from('Portable Photo Printer'),
            CatalogCandidateTitleFingerprint::from('Leather Wallet'),
        );
    }
}
