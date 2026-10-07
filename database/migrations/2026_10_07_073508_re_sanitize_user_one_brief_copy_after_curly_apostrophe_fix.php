<?php

use App\Models\DailyBrief;
use App\Models\User;
use App\Services\Brief\BorrowedCompetitorNameSanitizer;
use App\Services\Brief\DailyBriefCopySanitizer;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $user = User::query()->find(1);

        if ($user === null) {
            return;
        }

        app(BorrowedCompetitorNameSanitizer::class)->sanitizeStoredWeeklyIdeas($user, allWeeks: true);

        $briefs = DailyBrief::query()
            ->where('user_id', 1)
            ->where('status', 'ready')
            ->orderBy('id')
            ->get();

        $sanitizer = app(DailyBriefCopySanitizer::class);

        foreach ($briefs as $brief) {
            $sanitizer->sanitizeStored($brief);
        }
    }

    public function down(): void
    {
        // One-way copy cleanup.
    }
};
