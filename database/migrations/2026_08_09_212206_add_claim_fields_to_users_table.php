<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('created_via', 16)->default('web')->after('avatar');
            $table->string('claim_token', 64)->nullable()->unique()->after('created_via');
            $table->timestamp('claimed_at')->nullable()->after('claim_token');
        });

        // Agent-created accounts exist before WorkOS bind.
        Schema::table('users', function (Blueprint $table) {
            $table->string('workos_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['created_via', 'claim_token', 'claimed_at']);
        });
    }
};
