<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Automations\BriefAutomationKickoff;
use App\Support\ScheduleHeartbeat;
use Illuminate\Console\Command;
use Throwable;

class KickoffBriefAutomationsCommand extends Command
{
    protected $signature = 'snitch:kickoff-brief-automations
        {--user= : Limit to a single user id}';

    protected $description = 'Trigger Cursor Automations Executive Brief webhooks for users with trackers (no-op when not configured)';

    public function handle(BriefAutomationKickoff $kickoff): int
    {
        if (! $kickoff->configured()) {
            $this->info('Brief automation not configured; skipping.');

            return ScheduleHeartbeat::record('snitch:kickoff-brief-automations', self::SUCCESS);
        }

        $userFilter = $this->option('user');
        $users = User::query()
            ->whereHas('trackedAccounts')
            ->when(is_numeric($userFilter), fn ($query) => $query->where('id', (int) $userFilter))
            ->orderBy('id')
            ->get();

        $kicked = 0;
        $skipped = 0;

        foreach ($users as $user) {
            try {
                $result = $kickoff->kickOff($user);
            } catch (Throwable $e) {
                $this->warn("User {$user->id}: ".$e->getMessage());
                $skipped++;

                continue;
            }

            if ($result['kicked_off']) {
                $kicked++;
                $this->info("User {$user->id}: kicked off run ".$result['run']?->uuid);
            } else {
                $skipped++;
                $this->line("User {$user->id}: skipped (".$result['reason'].')');
            }
        }

        $this->info("Brief automations: kicked {$kicked}, skipped {$skipped}.");

        return ScheduleHeartbeat::record('snitch:kickoff-brief-automations', self::SUCCESS);
    }
}
