<?php

use Illuminate\Support\Facades\Schedule;

// Permanently remove accounts whose data retention window has elapsed.
// Requires a `schedule:run` cron (see deploy/README.md).
Schedule::command('users:purge')->daily();

// Draft expiration sequence — Day 3/6/7 reminders, Day 8 closure + card
// release. Requires a daily `schedule:run` cron; a missed day skips that
// cohort's step.
Schedule::command('drafts:expiration-reminders')->daily();

// Meeting reminders — dispatch reminders for bookings starting in 24h and 1h.
// Runs every 15 minutes; idempotent via cache dedup in SendMeetingReminderJob.
Schedule::command('meetings:reminders')->everyFifteenMinutes();

// Matchmaking - recompute cached recommendations daily so scores, privacy
// changes and new signals are reflected without waiting for a member request.
Schedule::command('recommendations:refresh')->daily();

// Appendix C - weekly recommendation digest. Runs weekly; the command avoids
// duplicate sends for the same profile/week.
Schedule::command('notifications:weekly-recommendations')->weekly();

// Appendix C - configured reminder windows. Each command is a no-op until the
// founder-approved delay is configured.
Schedule::command('notifications:unpublished-profile-reminders')->daily();
Schedule::command('notifications:subscription-lapse-reminders')->daily();

// Billing - expire ended/cancelled subscriptions and return members to Free
// limits without deleting their data.
Schedule::command('billing:process')->daily();

// Referrals - close attribution windows that have elapsed without qualifying.
Schedule::command('referrals:expire')->daily();

// Privacy - prune retention-limited records only when founder-approved values
// are configured. All windows default to "retained".
Schedule::command('data:prune')->daily();
