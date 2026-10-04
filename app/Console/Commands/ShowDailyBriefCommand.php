<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Brief\DailyBriefGenerator;
use App\Support\DailyBriefPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ShowDailyBriefCommand extends Command
{
    protected $signature = 'snitch:daily-brief
        {user : User id}
        {--date= : Brief date Y-m-d in Europe/London}
        {--json : Print the stored payload as JSON}';

    protected $description = 'Print a stored daily brief (read-only; does not generate)';

    public function handle(DailyBriefGenerator $generator, DailyBriefPresenter $presenter): int
    {
        $user = User::query()->find($this->argument('user'));

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $dateOption = $this->option('date');
        $date = is_string($dateOption) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOption) === 1
            ? CarbonImmutable::parse($dateOption, 'Europe/London')->startOfDay()
            : $generator->briefDate();

        $brief = $generator->briefForDate($user, $date);

        if ($brief === null) {
            $this->error("No daily brief stored for user {$user->id} on {$date->toDateString()}.");

            return self::FAILURE;
        }

        $payload = $presenter->payload($brief);

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return self::SUCCESS;
        }

        $this->line($presenter->toPlainText($payload));

        return self::SUCCESS;
    }
}
