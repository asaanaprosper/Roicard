<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\BillingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

class ProcessActivationJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public Payment $payment
    ) {}

    /**
     * Every verified payment enters through this one synchronous side-effect
     * path: membership activation, subscription activation or card binding.
     */
    public function handle(BillingService $billing): void
    {
        $billing->processSuccessfulPayment($this->payment->fresh() ?? $this->payment);
    }
}
