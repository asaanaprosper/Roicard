<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * Tags historical paid members into the named legacy_member group without
 * changing their plan or deleting data. Safe to run repeatedly.
 */
class TagLegacyMembers extends Command
{
    protected $signature = 'legacy:tag-members {--dry-run : Report candidates without writing}';

    protected $description = 'Tag historical paid members into the legacy_member group';

    public function handle(): int
    {
        $role = Role::firstOrCreate(['name' => 'legacy_member', 'guard_name' => 'web']);
        $userIds = Payment::where('status', Payment::STATUS_SUCCESS)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        $count = 0;
        foreach (User::whereIn('id', $userIds)->cursor() as $user) {
            if ($this->option('dry-run')) {
                $this->line("Would tag {$user->email}");
                continue;
            }

            $user->forceFill([
                'legacy_member' => true,
                'legacy_member_since' => $user->legacy_member_since ?? now(),
            ])->save();

            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }

            $count++;
        }

        $this->info($this->option('dry-run')
            ? 'Dry run complete.'
            : "Tagged {$count} legacy member(s).");

        return self::SUCCESS;
    }
}
