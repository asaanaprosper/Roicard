<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;

class ProcessBilling extends Command
{
    protected $signature = 'billing:process';

    protected $description = 'Expire ended subscriptions and return members to Free limits';

    public function handle(BillingService $billing): int
    {
        $result = $billing->processExpiredSubscriptions();

        $this->info("Billing processed: {$result['expired']} expired, {$result['cancelled']} cancelled, {$result['past_due']} past due.");

        return self::SUCCESS;
    }
}
