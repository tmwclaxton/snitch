<?php

namespace App\Mcp\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Runtime context for MCP agents (which app/DB they hit, whether jobs can run).
 */
class McpRuntime
{
    /**
     * @return array{
     *     app_url: string,
     *     environment: string,
     *     queue_connection: string,
     *     pending_jobs: int|null,
     *     failed_jobs: int,
     *     warnings: list<string>
     * }
     */
    public static function snapshot(): array
    {
        $appUrl = (string) config('app.url');
        $environment = (string) app()->environment();
        $queueConnection = (string) config('queue.default');
        $pending = self::pendingJobsCount();
        $failed = self::failedJobsCount();
        $warnings = [];

        if (str_contains(strtolower($appUrl), 'localhost') || str_contains(strtolower($appUrl), '127.0.0.1')) {
            $warnings[] = 'This MCP endpoint is local ('.$appUrl.'). Credits and data are NOT shared with production (https://www.snitchsocial.net). Top up and brand setup must be done in this environment.';
            $warnings[] = 'Local php artisan serve (composer run dev) is single-threaded: a long MCP tools/call or SSE hold can stall the browser dashboard until it finishes. Wait for the tool, pause MCP, or restart the serve process. Production uses php-fpm with multiple workers and is not blocked the same way.';
        }

        if ($pending !== null && $pending > 0) {
            $warnings[] = "There are {$pending} pending queue job(s). Async MCP tools (autofill, suggest, sync, find, analyze) need a worker: php artisan queue:work. If status stays pending, start a worker.";
        }

        if ($failed > 0) {
            $warnings[] = "There are {$failed} failed queue job(s). Check failed_jobs / logs if MCP async tools stall.";
        }

        $warnings[] = 'Never paste Sanctum bearer tokens into public chats. Prefer rotate_token after sharing a token.';

        return [
            'app_url' => $appUrl,
            'environment' => $environment,
            'queue_connection' => $queueConnection,
            'pending_jobs' => $pending,
            'failed_jobs' => $failed,
            'warnings' => $warnings,
        ];
    }

    private static function pendingJobsCount(): ?int
    {
        try {
            return max(0, (int) Queue::size());
        } catch (Throwable) {
            try {
                if (config('queue.default') === 'database') {
                    return (int) DB::table('jobs')->count();
                }
            } catch (Throwable) {
                //
            }

            return null;
        }
    }

    private static function failedJobsCount(): int
    {
        try {
            return (int) DB::table('failed_jobs')->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
