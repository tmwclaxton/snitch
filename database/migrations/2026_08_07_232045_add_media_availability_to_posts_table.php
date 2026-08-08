<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('media_availability')->default('available')->after('media_url');
            $table->timestamp('unavailable_at')->nullable()->after('media_availability');
            $table->string('unavailable_reason')->nullable()->after('unavailable_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['media_availability', 'unavailable_at', 'unavailable_reason']);
        });
    }
};
