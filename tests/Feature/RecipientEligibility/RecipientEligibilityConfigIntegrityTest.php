<?php

namespace Tests\Feature\RecipientEligibility;

use App\Actions\Discovery\QueryDiscoveryListingProductsAction;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Models\Relationship;
use App\RecipientEligibility\RecipientEligibilityConfig;
use Illuminate\Support\Facades\Log;
use Tests\Support\SeedsRecipientEligibilityCatalog;
use Tests\TestCase;

class RecipientEligibilityConfigIntegrityTest extends TestCase
{
    use SeedsRecipientEligibilityCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedEligibilityTaxonomy();
    }

    public function test_everything_ships_disabled(): void
    {
        $defaults = $this->shippedDefaults();

        $this->assertFalse($defaults['enabled']);
        $this->assertFalse($defaults['shadow']);
        $this->assertSame('off', $defaults['explicit_contradictions']);
        $this->assertFalse($defaults['finder_audience']);
        $this->assertFalse($defaults['pool_ordering']);
        $this->assertTrue($defaults['review_gate']['required']);

        foreach ($defaults['surfaces'] as $mode) {
            $this->assertSame('off', $mode);
        }

        foreach ($defaults['relationships'] as $profile) {
            $this->assertFalse($profile['infer']);
        }
    }

    public function test_phpunit_pins_every_recipient_eligibility_env_variable_to_strict_defaults(): void
    {
        preg_match_all("/env\\('(RECIPIENT_ELIGIBILITY_[A-Z_]+)'/", (string) file_get_contents(config_path('recipient_eligibility.php')), $matches);
        $phpunit = (string) file_get_contents(base_path('phpunit.xml'));

        $this->assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $variable) {
            $this->assertMatchesRegularExpression(
                '/<env name="'.$variable.'" value="[^"]*" force="true"\\/>/',
                $phpunit,
                "{$variable} must be pinned in phpunit.xml so a local .env cannot change test results",
            );
        }
    }

    public function test_every_seeded_relationship_has_a_profile_and_only_wave_one_is_guarded(): void
    {
        foreach (Relationship::query()->pluck('slug') as $slug) {
            $this->assertNotNull(RecipientEligibilityConfig::profile($slug), "{$slug} needs a profile");
        }

        $this->assertEqualsCanonicalizing(['husband', 'wife', 'boyfriend', 'girlfriend'], RecipientEligibilityConfig::waveOneSlugs());
    }

    public function test_deferred_relationships_can_never_infer_even_if_configured(): void
    {
        $this->enableRecipientEligibility([
            'relationships.brother.infer' => true,
            'relationships.sister.infer' => true,
            'relationships.friends.infer' => true,
        ]);

        $this->assertNotContains('brother', RecipientEligibilityConfig::inferringSlugs());
        $this->assertNotContains('sister', RecipientEligibilityConfig::inferringSlugs());
        $this->assertNotContains('friends', RecipientEligibilityConfig::inferringSlugs());
        $this->assertNull(RecipientEligibilityConfig::context('recipient_type'), 'recipient types stay off');
    }

    public function test_shadow_mode_logs_strict_and_eligible_totals_without_changing_results(): void
    {
        $this->eligibilityProduct('Hex Dumbbell Pair', ['occasions' => ['birthday'], 'genders' => ['unisex']]);
        $this->eligibilityProduct('Husband Mug', ['relationships' => ['husband'], 'occasions' => ['birthday']]);

        config(['recipient_eligibility.shadow' => true, 'recipient_eligibility.shadow_sample_rate' => 1.0, 'recipient_eligibility.relationships.husband.infer' => false]);

        Log::spy();

        $paginator = app(QueryDiscoveryListingProductsAction::class)->execute(
            DiscoveryListingContext::forRelationship(Relationship::query()->where('slug', 'husband')->firstOrFail()),
            new DiscoveryListingQueryState,
        );

        $this->assertSame(1, $paginator->total(), 'live results are unchanged');

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'recipient_eligibility.shadow'
            && $context['strict_total'] === 1
            && $context['eligible_total'] === 2
            && $context['delta'] === 1)->once();
    }

    public function test_shadow_logging_is_silent_when_disabled_or_unsampled(): void
    {
        $this->eligibilityProduct('Husband Mug', ['relationships' => ['husband'], 'occasions' => ['birthday']]);
        $context = DiscoveryListingContext::forRelationship(Relationship::query()->where('slug', 'husband')->firstOrFail());

        Log::spy();

        config(['recipient_eligibility.shadow' => false]);
        app(QueryDiscoveryListingProductsAction::class)->execute($context, new DiscoveryListingQueryState);

        config(['recipient_eligibility.shadow' => true, 'recipient_eligibility.shadow_sample_rate' => 0.0]);
        app(QueryDiscoveryListingProductsAction::class)->execute($context, new DiscoveryListingQueryState);

        Log::shouldNotHaveReceived('info');
    }

    /**
     * Evaluates the config file as shipped: every RECIPIENT_ELIGIBILITY_* variable
     * is removed from the process environment for the duration of the read, so
     * neither .env, the shell nor phpunit.xml can influence the result.
     *
     * @return array<string, mixed>
     */
    private function shippedDefaults(): array
    {
        preg_match_all("/env\\('(RECIPIENT_ELIGIBILITY_[A-Z_]+)'/", (string) file_get_contents(config_path('recipient_eligibility.php')), $matches);

        $saved = [];

        foreach (array_unique($matches[1]) as $variable) {
            $saved[$variable] = [
                'env' => $_ENV[$variable] ?? null,
                'server' => $_SERVER[$variable] ?? null,
                'process' => getenv($variable),
            ];
            unset($_ENV[$variable], $_SERVER[$variable]);
            putenv($variable);
        }

        try {
            return require config_path('recipient_eligibility.php');
        } finally {
            foreach ($saved as $variable => $values) {
                if ($values['env'] !== null) {
                    $_ENV[$variable] = $values['env'];
                }
                if ($values['server'] !== null) {
                    $_SERVER[$variable] = $values['server'];
                }
                if ($values['process'] !== false) {
                    putenv($variable.'='.$values['process']);
                }
            }
        }
    }
}
