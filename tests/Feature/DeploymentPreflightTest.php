<?php

namespace Tests\Feature;

use App\Console\Commands\DeploymentPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_reports_unsafe_staging_configuration(): void
    {
        config([
            'app.env' => 'staging',
            'app.key' => '',
            'app.debug' => true,
            'app.url' => 'http://staging.example.test',
            'app.frontend_url' => 'http://staging.example.test',
            'cors.allowed_origins' => ['*'],
            'database.default' => 'sqlite',
            'session.secure' => false,
            'session.http_only' => false,
            'queue.default' => 'sync',
            'mail.default' => 'log',
            'app.payment_provider' => 'mock',
            'roicard.billing.allow_mock' => true,
        ]);

        $issues = app(DeploymentPreflight::class)->inspect();
        $keys = collect($issues)->pluck('key')->all();

        foreach (['APP_KEY', 'APP_DEBUG', 'CORS_ALLOWED_ORIGINS', 'DB_CONNECTION', 'SESSION_SECURE_COOKIE', 'SESSION_HTTP_ONLY', 'QUEUE_CONNECTION', 'MAIL_MAILER', 'PAYMENT_PROVIDER', 'BILLING_ALLOW_MOCK'] as $key) {
            $this->assertContains($key, $keys);
        }

        $this->assertGreaterThan(0, collect($issues)->where('level', 'error')->count());
    }

    public function test_preflight_is_read_only_and_reports_no_blocking_errors_for_local_test_configuration(): void
    {
        config([
            'app.env' => 'testing',
            'app.key' => 'base64:test',
            'app.debug' => true,
            'app.url' => 'http://localhost:8000',
            'app.frontend_url' => 'http://localhost:3000',
            'cors.allowed_origins' => ['http://localhost:3000'],
            'database.default' => 'sqlite',
            'session.secure' => false,
            'session.http_only' => true,
            'queue.default' => 'sync',
            'mail.default' => 'array',
            'app.payment_provider' => 'mock',
            'roicard.billing.allow_mock' => true,
        ]);

        $before = \App\Models\User::count();
        $issues = app(DeploymentPreflight::class)->inspect();

        $this->assertSame(0, collect($issues)->where('level', 'error')->count());
        $this->assertSame($before, \App\Models\User::count());

        $this->artisan('deploy:preflight')->assertSuccessful();
    }
}
