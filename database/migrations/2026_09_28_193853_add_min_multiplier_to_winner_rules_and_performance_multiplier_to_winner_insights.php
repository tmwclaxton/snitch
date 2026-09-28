<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('winner_rules', function (Blueprint $table) {
            $table->decimal('min_multiplier', 5, 2)->default(2.00)->after('min_likes');
        });

        Schema::table('winner_insights', function (Blueprint $table) {
            $table->decimal('performance_multiplier', 8, 2)->nullable()->after('score');
            $table->index(['user_id', 'performance_multiplier']);
        });
    }

    public function down(): void
    {
        Schema::table('winner_insights', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'performance_multiplier']);
            $table->dropColumn('performance_multiplier');
        });

        Schema::table('winner_rules', function (Blueprint $table) {
            $table->dropColumn('min_multiplier');
        });
    }
};
