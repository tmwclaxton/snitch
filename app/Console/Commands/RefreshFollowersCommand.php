<?php

namespace App\Console\Commands;

use App\Jobs\RefreshFollowerCountJob;
use App\Models\FollowerSnapshot;
use App\Models\SocialAccount;
use App\Services\Tracking\FollowerCountRefresher;
use App\Support\ScheduleHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snitch:refresh-followers {--force : Re-fetch even when a profile snapshot already exists today} {--queue : Enqueue jobs instead of running inline}')]
#[Description('Refresh Instagram follower counts for social accounts someone still tracks')]
class RefreshFollowersCommand extends Command
{
    public function handle(FollowerCountRefresher $refresher): int
    {
        $force = (bool) $this->option('force');
        $queue = (bool) $this->option('queue');
        $ids = $force
            ? $refresher->allTrackedInstagramSocialAccountIds()
            : $refresher->dueSocialAccountIds();

        $backup = $this->backupTodaySnapshots();
        if ($backup !== null) {
            $this->info('Backed up today\'s follower snapshots to '.$backup);
        }

        if ($queue) {
            foreach ($ids as $id) {
                RefreshFollowerCountJob::dispatch($id, $force);
            }

            $this->info('Enqueued '.count($ids).' follower refreshes.');

            return ScheduleHeartbeat::record('snitch:refresh-followers', self::SUCCESS);
        }

        $ok = 0;
        $fail = 0;

        foreach ($ids as $id) {
            $social = SocialAccount::query()->find($id);

            if ($social === null) {
                $fail++;

                continue;
            }

            if ($refresher->refresh($social, $force)) {
                $ok++;

                continue;
            }

            $fail++;
            RefreshFollowerCountJob::dispatch($id, $force)->delay(now()->addHours(4));
        }

        $this->info("Refreshed {$ok} follower count(s); {$fail} failed.");

        if ($fail > 0 && $fail >= $ok) {
            $this->error('Follower refresh failed for every or most accounts.');

            return ScheduleHeartbeat::record('snitch:refresh-followers', self::FAILURE);
        }

        return ScheduleHeartbeat::record('snitch:refresh-followers', self::SUCCESS);
    }

    private function backupTodaySnapshots(): ?string
    {
        $day = now()->toDateString();
        $rows = FollowerSnapshot::query()
            ->whereDate('captured_on', $day)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $dir = storage_path('app/backups');

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->warn('Could not create snapshot backup directory.');

            return null;
        }

        $path = $dir.'/follower-snapshots-'.$day.'.csv';
        $handle = fopen($path, 'w');

        if ($handle === false) {
            $this->warn('Could not write snapshot backup CSV.');

            return null;
        }

        fputcsv($handle, ['id', 'social_account_id', 'followers', 'source', 'captured_on', 'created_at', 'updated_at']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row->id,
                $row->social_account_id,
                $row->followers,
                $row->source,
                $row->captured_on?->toDateString(),
                $row->created_at?->toDateTimeString(),
                $row->updated_at?->toDateTimeString(),
            ]);
        }

        fclose($handle);

        return $path;
    }
}
