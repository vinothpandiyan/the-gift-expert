<?php

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_mvp_domain_tables_exist(): void
    {
        $tables = [
            'merchants',
            'categories',
            'occasions',
            'relationships',
            'recipient_types',
            'interests',
            'professions',
            'gift_types',
            'budget_ranges',
            'products',
            'product_images',
            'affiliate_links',
            'category_product',
            'occasion_product',
            'relationship_product',
            'recipient_type_product',
            'interest_product',
            'profession_product',
            'gift_type_product',
            'recommendation_sessions',
            'recommendation_session_interests',
            'recommendation_results',
            'affiliate_clicks',
            'category_path_redirects',
            'product_slug_redirects',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_excluded_tables_are_not_present(): void
    {
        $this->assertFalse(Schema::hasTable('gifts'));
        $this->assertFalse(Schema::hasTable('gift_attributes'));
        $this->assertFalse(Schema::hasTable('gift_attribute_options'));
        $this->assertFalse(Schema::hasTable('product_attribute_values'));
    }
}
