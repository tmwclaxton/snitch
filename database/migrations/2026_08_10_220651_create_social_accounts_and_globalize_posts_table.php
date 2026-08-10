<?php

use App\Enums\AnalysisStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('platform');
            $table->string('handle');
            $table->string('url')->nullable();
            $table->string('external_id')->nullable();
            $table->text('avatar')->nullable();
            $table->string('display_name')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'handle']);
            $table->unique(['platform', 'external_id']);
            $table->index('external_id');
        });

        Schema::table('tracked_accounts', function (Blueprint $table) {
            $table->foreignId('social_account_id')
                ->nullable()
                ->after('user_id')
                ->constrained('social_accounts')
                ->restrictOnDelete();
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('social_account_id')
                ->nullable()
                ->after('id')
                ->constrained('social_accounts')
                ->restrictOnDelete();
        });

        $this->backfillSocialAccounts();
        $this->mergeDuplicatePosts();

        Schema::table('posts', function (Blueprint $table) {
            $table->dropUnique(['tracked_account_id', 'external_id']);
        });

        // Drop ownership indexes/FKs so untracking / user delete cannot wipe the corpus.
        // SQLite cannot drop indexed columns until the indexes are removed first.
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'platform', 'type']);
            $table->dropIndex(['user_id', 'posted_at']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['tracked_account_id']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['user_id', 'tracked_account_id']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->unique(['social_account_id', 'external_id']);
            $table->index(['platform', 'type', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropUnique(['social_account_id', 'external_id']);
            $table->dropIndex(['platform', 'type', 'posted_at']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id');
            $table->foreignId('tracked_account_id')->nullable()->after('user_id');
        });

        // Best-effort restore: point each post at one tracked membership for its social account.
        $memberships = DB::table('tracked_accounts')
            ->orderBy('id')
            ->get(['id', 'user_id', 'social_account_id'])
            ->groupBy('social_account_id');

        DB::table('posts')->orderBy('id')->chunkById(200, function (Collection $posts) use ($memberships): void {
            foreach ($posts as $post) {
                $members = $memberships->get($post->social_account_id, collect());
                $member = $members->first();
                DB::table('posts')->where('id', $post->id)->update([
                    'user_id' => $member->user_id ?? null,
                    'tracked_account_id' => $member->id ?? null,
                ]);
            }
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['social_account_id']);
            $table->dropColumn('social_account_id');
        });

        Schema::table('tracked_accounts', function (Blueprint $table) {
            $table->dropForeign(['social_account_id']);
            $table->dropColumn('social_account_id');
        });

        Schema::dropIfExists('social_accounts');
    }

    private function backfillSocialAccounts(): void
    {
        $now = now();

        DB::table('tracked_accounts')->orderBy('id')->chunkById(200, function (Collection $accounts) use ($now): void {
            foreach ($accounts as $account) {
                $platform = (string) $account->platform;
                $handle = $this->normalizeHandle((string) $account->handle);
                $externalId = filled($account->external_id) ? (string) $account->external_id : null;

                $socialId = null;

                if ($externalId !== null) {
                    $socialId = DB::table('social_accounts')
                        ->where('platform', $platform)
                        ->where('external_id', $externalId)
                        ->value('id');
                }

                if ($socialId === null) {
                    $socialId = DB::table('social_accounts')
                        ->where('platform', $platform)
                        ->where('handle', $handle)
                        ->value('id');
                }

                if ($socialId === null) {
                    $createHandle = $handle;
                    $handleTaken = DB::table('social_accounts')
                        ->where('platform', $platform)
                        ->where('handle', $handle)
                        ->exists();

                    if ($handleTaken && $externalId !== null) {
                        $createHandle = $handle.'-'.$externalId;
                    }

                    $socialId = DB::table('social_accounts')->insertGetId([
                        'platform' => $platform,
                        'handle' => $createHandle,
                        'url' => $account->url,
                        'external_id' => $externalId,
                        'avatar' => $account->avatar,
                        'display_name' => $account->display_name,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    $updates = array_filter([
                        'url' => $account->url,
                        'avatar' => $account->avatar,
                        'display_name' => $account->display_name,
                        'updated_at' => $now,
                    ], static fn (mixed $value): bool => $value !== null && $value !== '');

                    $existingExternal = DB::table('social_accounts')->where('id', $socialId)->value('external_id');
                    if ($externalId !== null && blank($existingExternal)) {
                        $updates['external_id'] = $externalId;
                    }

                    if ($updates !== []) {
                        DB::table('social_accounts')->where('id', $socialId)->update($updates);
                    }
                }

                DB::table('tracked_accounts')->where('id', $account->id)->update([
                    'social_account_id' => $socialId,
                    'handle' => $handle,
                ]);

                DB::table('posts')
                    ->where('tracked_account_id', $account->id)
                    ->whereNull('social_account_id')
                    ->update(['social_account_id' => $socialId]);
            }
        });

        // Orphan posts (no tracked account row) get a synthetic social account.
        DB::table('posts')
            ->whereNull('social_account_id')
            ->orderBy('id')
            ->chunkById(200, function (Collection $posts) use ($now): void {
                foreach ($posts as $post) {
                    $platform = (string) $post->platform;
                    $externalId = filled($post->external_id) ? (string) $post->external_id : ('orphan-'.$post->id);
                    $handle = 'unknown-'.$post->id;

                    $socialId = DB::table('social_accounts')
                        ->where('platform', $platform)
                        ->where('external_id', $externalId)
                        ->value('id');

                    if ($socialId === null) {
                        $socialId = DB::table('social_accounts')->insertGetId([
                            'platform' => $platform,
                            'handle' => $handle,
                            'url' => null,
                            'external_id' => $externalId,
                            'avatar' => null,
                            'display_name' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    DB::table('posts')->where('id', $post->id)->update([
                        'social_account_id' => $socialId,
                    ]);
                }
            });
    }

    private function mergeDuplicatePosts(): void
    {
        // Use HAVING COUNT(*) (not the select alias): Postgres treats having('aggregate')
        // as a quoted column reference and fails with "column aggregate does not exist".
        $groups = DB::table('posts')
            ->select('platform', 'external_id', DB::raw('COUNT(*) as aggregate'))
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '')
            ->groupBy('platform', 'external_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $posts = DB::table('posts')
                ->where('platform', $group->platform)
                ->where('external_id', $group->external_id)
                ->orderBy('id')
                ->get();

            if ($posts->count() < 2) {
                continue;
            }

            $completedIds = DB::table('post_analyses')
                ->whereIn('post_id', $posts->pluck('id'))
                ->where('status', AnalysisStatus::Completed->value)
                ->pluck('post_id')
                ->all();

            $keeper = $posts->first(fn ($post) => in_array($post->id, $completedIds, true))
                ?? $posts->first();

            foreach ($posts as $post) {
                if ((int) $post->id === (int) $keeper->id) {
                    continue;
                }

                $this->reassignChildRows((int) $post->id, (int) $keeper->id);
                DB::table('posts')->where('id', $post->id)->delete();
            }

            // Prefer a social_account that still has tracked memberships.
            $socialId = $keeper->social_account_id;
            DB::table('posts')->where('id', $keeper->id)->update([
                'social_account_id' => $socialId,
            ]);
        }
    }

    private function reassignChildRows(int $fromPostId, int $toPostId): void
    {
        $fromAnalysis = DB::table('post_analyses')->where('post_id', $fromPostId)->first();
        $toAnalysis = DB::table('post_analyses')->where('post_id', $toPostId)->first();

        if ($fromAnalysis !== null && $toAnalysis === null) {
            DB::table('post_analyses')->where('id', $fromAnalysis->id)->update([
                'post_id' => $toPostId,
            ]);
        } elseif ($fromAnalysis !== null && $toAnalysis !== null) {
            $fromCompleted = ($fromAnalysis->status ?? null) === AnalysisStatus::Completed->value;
            $toCompleted = ($toAnalysis->status ?? null) === AnalysisStatus::Completed->value;

            if ($fromCompleted && ! $toCompleted) {
                DB::table('analysis_term_post_analysis')->where('post_analysis_id', $toAnalysis->id)->delete();
                DB::table('post_analyses')->where('id', $toAnalysis->id)->delete();
                DB::table('post_analyses')->where('id', $fromAnalysis->id)->update([
                    'post_id' => $toPostId,
                ]);
            } else {
                DB::table('analysis_term_post_analysis')->where('post_analysis_id', $fromAnalysis->id)->delete();
                DB::table('post_analyses')->where('id', $fromAnalysis->id)->delete();
            }
        }

        $insights = DB::table('winner_insights')->where('post_id', $fromPostId)->get();
        foreach ($insights as $insight) {
            $exists = DB::table('winner_insights')
                ->where('user_id', $insight->user_id)
                ->where('post_id', $toPostId)
                ->exists();

            if ($exists) {
                DB::table('winner_insights')->where('id', $insight->id)->delete();
            } else {
                DB::table('winner_insights')->where('id', $insight->id)->update([
                    'post_id' => $toPostId,
                ]);
            }
        }
    }

    private function normalizeHandle(string $handle): string
    {
        return mb_strtolower(ltrim(trim($handle), '@'));
    }
};
