<?php

namespace App\Console\Commands;

use App\Services\ReferralService;
use Illuminate\Console\Command;

class ExpireReferrals extends Command
{
    protected $signature = 'referrals:expire';

    protected $description = 'Mark referral attributions whose attribution window has closed as expired';

    public function handle(ReferralService $referrals): int
    {
        $count = $referrals->expireStaleAttributions();

        $this->info("Referral attributions expired: {$count}.");

        return self::SUCCESS;
    }
}
