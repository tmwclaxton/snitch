<?php

use App\Models\User;
use App\Services\Brief\BorrowedCompetitorNameSanitizer;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $user = User::query()->find(1);

        if ($user === null) {
            return;
        }

        app(BorrowedCompetitorNameSanitizer::class)->sanitizeStoredWeeklyIdeas($user);
    }

    public function down(): void
    {
        // One-way copy cleanup.
    }
};
