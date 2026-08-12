<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_code_id')->constrained()->cascadeOnDelete();
            $table->string('ip_hash', 64);
            $table->string('user_agent', 255)->nullable();
            $table->date('visit_date');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['referral_code_id', 'ip_hash', 'visit_date']);
            $table->index(['referral_code_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_visits');
    }
};
