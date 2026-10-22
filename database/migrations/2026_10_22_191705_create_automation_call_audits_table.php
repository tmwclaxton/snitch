<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_call_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_run_id')->constrained('automation_runs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tool', 120);
            $table->boolean('ok')->default(true);
            $table->string('error_code', 64)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['automation_run_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_call_audits');
    }
};
