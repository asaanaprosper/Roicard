<?php

namespace App\Console\Commands;

use App\Models\Plan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Non-destructive staging/production readiness report.
 *
 * This command never changes configuration, files, migrations or data. It only
 * reads configuration and, when available, the database.
 */
class DeploymentPreflight extends Command
{
    protected $signature = 'deploy:preflight {--strict : Treat warnings as a failed preflight} {--json : Output machine-readable JSON}';

    protected $description = 'Report staging/production configuration and readiness issues without changing anything';

    public function handle(): int
    {
        $issues = $this->inspect();
        $errors = collect($issues)->where('level', 'error')->count();
        $warnings = collect($issues)->where('level', 'warning')->count();

        if ($this->option('json')) {
            $this->line(json_encode([
                'environment' => config('app.env'),
                'errors' => $errors,
                'warnings' => $warnings,
                'issues' => $issues,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->info('ROICARD deployment preflight — read-only');
            $this->line('Environment: ' . (config('app.env') ?: 'unset'));

            if ($issues === []) {
                $this->info('No readiness issues detected.');
            } else {
                $this->table(
                    ['Level', 'Key', 'Message'],
                    collect($issues)->map(fn ($issue) => [
                        strtoupper($issue['level']),
                        $issue['key'],
                        $issue['message'],
                    ])->all(),
                );
            }

            $this->line("Errors: {$errors}; warnings: {$warnings}");
            $this->comment('No files, settings, migrations or data were changed.');
        }

        if ($errors > 0) {
            return self::FAILURE;
        }

        if ($this->option('strict') && $warnings > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{level: string, key: string, message: string}>
     */
    public function inspect(): array
    {
        $issues = [];
        $add = function (string $level, string $key, string $message) use (&$issues) {
            $issues[] = ['level' => $level, 'key' => $key, 'message' => $message];
        };

        $environment = (string) config('app.env', 'production');
        $isLocal = in_array($environment, ['local', 'testing'], true);
        $isSecureUrl = fn (string $url) => str_starts_with($url, 'https://');

        if (! config('app.key')) {
            $add('error', 'APP_KEY', 'APP_KEY is missing. Run php artisan key:generate on the target environment.');
        }

        if (! $isLocal && config('app.debug')) {
            $add('error', 'APP_DEBUG', 'APP_DEBUG must be false outside local/testing.');
        }

        if (! $isLocal && ! app()->configurationIsCached()) {
            $add('notice', 'CONFIG_CACHE', 'Configuration is not cached on this non-local environment. Run php artisan config:cache after final .env changes.');
        }

        $appUrl = (string) config('app.url');
        if ($appUrl === '') {
            $add('error', 'APP_URL', 'APP_URL is not set.');
        } elseif (! $isLocal && ! $isSecureUrl($appUrl)) {
            $add('warning', 'APP_URL', 'APP_URL is not HTTPS. Use HTTPS for staging/production.');
        }

        $frontendUrl = (string) config('app.frontend_url');
        if ($frontendUrl === '') {
            $add('error', 'FRONTEND_URL', 'FRONTEND_URL is not set.');
        } elseif (! $isLocal && ! $isSecureUrl($frontendUrl)) {
            $add('warning', 'FRONTEND_URL', 'FRONTEND_URL is not HTTPS.');
        }

        $origins = config('cors.allowed_origins', []);
        if (! is_array($origins)) {
            $origins = [$origins];
        }
        if (in_array('*', $origins, true)) {
            $add('error', 'CORS_ALLOWED_ORIGINS', 'Wildcard CORS is not safe with credentialed API traffic.');
        }
        if (! $isLocal) {
            foreach ($origins as $origin) {
                if (str_contains((string) $origin, 'localhost') || str_contains((string) $origin, '127.0.0.1')) {
                    $add('warning', 'CORS_ALLOWED_ORIGINS', "Local origin [{$origin}] is configured outside local/testing.");
                }
            }
            if ($frontendUrl !== '' && ! in_array($frontendUrl, $origins, true)) {
                $add('warning', 'CORS_ALLOWED_ORIGINS', 'FRONTEND_URL is not present in CORS_ALLOWED_ORIGINS.');
            }
        }

        if (! $isLocal && config('database.default') === 'sqlite') {
            $add('error', 'DB_CONNECTION', 'SQLite is configured outside local/testing. Use the staging MySQL/MariaDB database.');
        }
        if (config('database.default') !== 'sqlite' && ! config('database.connections.' . config('database.default') . '.database')) {
            $add('error', 'DB_DATABASE', 'Database name is not configured.');
        }

        try {
            DB::connection()->getPdo();
            if (! Schema::hasTable('users')) {
                $add('warning', 'DATABASE', 'Database connection works but the users table is missing. Run migrations.');
            } else {
                try {
                    \Illuminate\Support\Facades\Artisan::call('migrate:status');
                    if (str_contains(\Illuminate\Support\Facades\Artisan::output(), 'Pending')) {
                        $add('warning', 'MIGRATIONS', 'Pending database migrations were detected. Run php artisan migrate --force after backup.');
                    }
                } catch (\Throwable $e) {
                    $add('warning', 'MIGRATIONS', 'Could not check migration status: ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            $add('error', 'DATABASE', 'Database connection failed: ' . $e->getMessage());
        }

        $localDisk = config('filesystems.disks.local');
        $privateRoot = $localDisk['root'] ?? storage_path('app/private');
        if (! is_dir($privateRoot)) {
            $add('warning', 'PRIVATE_STORAGE', "Private storage directory [{$privateRoot}] does not exist.");
        } elseif (! is_writable($privateRoot)) {
            $add('error', 'PRIVATE_STORAGE', "Private storage directory [{$privateRoot}] is not writable.");
        }
        if (str_starts_with(realpath($privateRoot) ?: $privateRoot, realpath(public_path()) ?: public_path())) {
            $add('error', 'PRIVATE_STORAGE', 'The private storage root is inside the public web root. CVs must not be publicly served.');
        }

        $publicStorage = public_path('storage');
        if (! file_exists($publicStorage)) {
            $add('warning', 'PUBLIC_STORAGE', 'public/storage link is missing. Run php artisan storage:link for public profile/catalog media.');
        }

        $cvCollection = config('media-library.disk_name', 'public');
        if ($cvCollection === 'local' && str_starts_with($privateRoot, realpath(public_path()) ?: public_path())) {
            $add('error', 'CV_DISK', 'CV private disk resolves inside the public document root.');
        }

        try {
            if (Schema::hasTable('media')) {
                $legacyCvs = Media::query()
                    ->where('model_type', \App\Models\Profile::class)
                    ->where('collection_name', 'cv')
                    ->where('disk', '!=', 'local')
                    ->count();

                if ($legacyCvs > 0) {
                    $add('warning', 'LEGACY_CVS', "{$legacyCvs} CV media record(s) still use a non-private disk. Run media:migrate-private-cvs --dry-run first.");
                }
            }
        } catch (\Throwable $e) {
            $add('warning', 'LEGACY_CVS', 'Could not inspect legacy CV media: ' . $e->getMessage());
        }

        if (! $isLocal && config('session.secure') !== true) {
            $add('warning', 'SESSION_SECURE_COOKIE', 'SESSION_SECURE_COOKIE should be true under HTTPS.');
        }
        if (config('session.http_only') !== true) {
            $add('error', 'SESSION_HTTP_ONLY', 'SESSION_HTTP_ONLY must be true.');
        }
        if (! $isLocal && config('session.driver') === 'array') {
            $add('error', 'SESSION_DRIVER', 'The array session driver is not valid outside local/testing.');
        }

        if (! $isLocal && config('queue.default') === 'sync') {
            $add('error', 'QUEUE_CONNECTION', 'QUEUE_CONNECTION is sync. A staging worker is required for queued notifications and jobs.');
        }

        $mailer = (string) config('mail.default');
        if (! $isLocal && in_array($mailer, ['log', 'array'], true)) {
            $add('error', 'MAIL_MAILER', "MAIL_MAILER [{$mailer}] does not deliver mail outside local/testing.");
        }

        $paymentProvider = (string) config('app.payment_provider', 'mock');
        if (! $isLocal) {
            if ($paymentProvider === 'mock') {
                $add('error', 'PAYMENT_PROVIDER', 'Mock payments are configured outside local/testing. Set a real provider or do not accept payments.');
            }
            if (config('roicard.billing.allow_mock', false)) {
                $add('error', 'BILLING_ALLOW_MOCK', 'BILLING_ALLOW_MOCK must be false outside local/testing.');
            }
        }

        if ($paymentProvider === 'paystack' && ! config('services.paystack.secret_key')) {
            $add('error', 'PAYSTACK_SECRET_KEY', 'Paystack is selected but PAYSTACK_SECRET_KEY is empty.');
        }
        if ($paymentProvider === 'hubtel'
            && (! config('services.hubtel.client_id') || ! config('services.hubtel.client_secret'))) {
            $add('error', 'HUBTEL_CREDENTIALS', 'Hubtel is selected but client credentials are incomplete.');
        }

        try {
            if (Schema::hasTable('plans')) {
                if (! Plan::query()->exists()) {
                    $add('warning', 'PLANS', 'No plans exist. Run php artisan db:seed --class=PlanSeeder --force.');
                } else {
                    $priced = Plan::query()->whereNotNull('price')->where('price', '>', 0)->count();
                    if ($priced === 0) {
                        $add('notice', 'PLANS', 'No plan has founder-approved pricing yet; plan upgrades will remain unavailable.');
                    }
                }
            }
        } catch (\Throwable $e) {
            $add('warning', 'PLANS', 'Could not inspect plans: ' . $e->getMessage());
        }

        if (config('roicard.referrals.enabled')
            && (! config('roicard.referrals.qualification_trigger') || config('roicard.referrals.reward_amount') === null)) {
            $add('warning', 'REFERRALS', 'Referral program is enabled but qualification trigger or reward amount is not configured.');
        }

        $add('notice', 'SCHEDULER', 'Verify the cron entry runs php artisan schedule:run every minute and that billing:process is enabled.');
        $add('notice', 'WEBHOOKS', 'Register provider webhooks at /api/payments/webhook/{provider}; never paste credentials into this checklist.');

        return $issues;
    }
}
