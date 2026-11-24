<?php

namespace Tests\Concerns;

use Carbon\Carbon;

/**
 * Freezes the meeting test clock to a known Tuesday so relative dates such
 * as "three days from now" deterministically land on a weekday. This keeps
 * the scheduling suites independent of the calendar day they are run on.
 */
trait FreezesMeetingClock
{
    protected function freezeMeetingClock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
