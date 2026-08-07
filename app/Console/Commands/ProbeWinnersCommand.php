<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Winners\WinnerScorer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snitch:probe-winners {user : User id or email}')]
#[Description('Print top winner scores and rule verdicts for a user')]
class ProbeWinnersCommand extends Command
{
    public function handle(WinnerScorer $scorer): int
    {
        $identifier = (string) $this->argument('user');
        $user = is_numeric($identifier)
            ? User::query()->find($identifier)
            : User::query()->where('email', $identifier)->first();

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $rule = $scorer->ruleFor($user);
        $insights = $scorer->rescoreUser($user)->take(10);

        $this->info("Winner rules preset: {$rule->preset}");

        foreach ($insights as $insight) {
            $this->line(sprintf(
                '#%d score=%s why=%s',
                $insight->post_id,
                $insight->score,
                str($insight->why)->limit(120),
            ));
        }

        if ($insights->isEmpty()) {
            $this->warn('No winners matched the current rules.');
        }

        return self::SUCCESS;
    }
}
