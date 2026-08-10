<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracked_accounts', function (Blueprint $table) {
            $table->text('fit_reason')->nullable()->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_accounts', function (Blueprint $table) {
            $table->dropColumn('fit_reason');
        });
    }
};
