<?php

namespace App\Console\Commands\Automations;

use App\Models\AutomationRun;
use App\Models\User;
use App\Services\Automations\BriefAutomationKickoff;
use App\Services\Automations\CursorWebhookTriggerGuard;
use Illuminate\Console\Command;
use Throwable;

class AutomationBriefCommand extends Command
{
    protected $signature = 'snitch:automation-brief
        {user : User id to kick off}
        {--force : Bypass the recent-trigger guard}';

    protected $description = 'Trigger the Cursor Automations Executive Brief webhook for one user';

    public function handle(BriefAutomationKickoff $kickoff, CursorWebhookTriggerGuard $guard): int
    {
        $userId = (int) $this->argument('user');
        $user = User::query()->find($userId);

        if ($user === null) {
            $this->error("User {$userId} not found.");

            return self::FAILURE;
        }

        if (! $kickoff->configured()) {
            $this->error('Brief automation is not configured (CURSOR_BRIEF_WEBHOOK_URL / TOKEN).');

            return self::FAILURE;
        }

        if ($this->option('force')) {
            $guard->forget(AutomationRun::TYPE_BRIEF, $userId);
        }

        try {
            $result = $kickoff->kickOff($user);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $result['kicked_off']) {
            $this->warn("Skipped: {$result['reason']}");

            return self::FAILURE;
        }

        $run = $result['run'];
        $this->info("Kicked off brief automation for user {$userId}; run {$run?->uuid}");

        return self::SUCCESS;
    }
}
