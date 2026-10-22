<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curator_mcp_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->string('tool', 255);
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('ok')->default(true);
            $table->string('error_code', 255)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tool', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curator_mcp_audits');
    }
};
