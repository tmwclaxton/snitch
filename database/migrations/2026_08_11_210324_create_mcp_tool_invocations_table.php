<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tool_invocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool');
            $table->boolean('ok');
            $table->string('error_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('auth', 32)->nullable();
            $table->timestamps();

            $table->index(['tool', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_invocations');
    }
};
