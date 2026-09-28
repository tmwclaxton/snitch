<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->text('avatar_source_url')->nullable()->after('avatar');
        });

        Schema::table('tracked_accounts', function (Blueprint $table) {
            $table->text('avatar_source_url')->nullable()->after('avatar');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->text('cover_source_url')->nullable()->after('cover_url');
        });

        // Copy only: leave existing avatar/cover values untouched.
        DB::table('social_accounts')
            ->whereNotNull('avatar')
            ->where('avatar', 'like', 'http%')
            ->whereNull('avatar_source_url')
            ->update([
                'avatar_source_url' => DB::raw('avatar'),
            ]);

        DB::table('tracked_accounts')
            ->whereNotNull('avatar')
            ->where('avatar', 'like', 'http%')
            ->whereNull('avatar_source_url')
            ->update([
                'avatar_source_url' => DB::raw('avatar'),
            ]);

        DB::table('posts')
            ->whereNotNull('cover_url')
            ->where('cover_url', 'like', 'http%')
            ->whereNull('cover_source_url')
            ->update([
                'cover_source_url' => DB::raw('cover_url'),
            ]);
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('cover_source_url');
        });

        Schema::table('tracked_accounts', function (Blueprint $table) {
            $table->dropColumn('avatar_source_url');
        });

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn('avatar_source_url');
        });
    }
};
