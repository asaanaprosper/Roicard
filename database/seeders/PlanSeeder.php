<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Creates the four MVP 2 plan shells without inventing prices or limits.
 *
 * Existing plans are never overwritten: pricing, intervals, entitlements and
 * the verification-mark flag remain founder/admin-managed data.
 *
 * Usage: php artisan db:seed --class=PlanSeeder --force
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['key' => 'free', 'name' => 'Free', 'description' => 'Default access after membership activation.', 'sort_order' => 0, 'is_default' => true],
            ['key' => 'standard', 'name' => 'Standard', 'description' => 'Standard member plan.', 'sort_order' => 10, 'is_default' => false],
            ['key' => 'professional', 'name' => 'Professional', 'description' => 'Professional member plan.', 'sort_order' => 20, 'is_default' => false],
            ['key' => 'organization', 'name' => 'Organization', 'description' => 'Organization workspace plan.', 'sort_order' => 30, 'is_default' => false],
        ];

        foreach ($plans as $plan) {
            Plan::firstOrCreate(
                ['key' => $plan['key']],
                [
                    'name' => $plan['name'],
                    'description' => $plan['description'],
                    'is_active' => true,
                    'is_default' => $plan['is_default'],
                    'sort_order' => $plan['sort_order'],
                    'price' => null,
                    'currency' => null,
                    'billing_interval' => null,
                    'verification_mark' => false,
                    'metadata' => $plan['key'] === 'organization'
                        ? ['organization_plan' => true]
                        : null,
                ],
            );
        }
    }
}
