<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_panels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('type', 32);
            $table->string('title');
            $table->json('payload')->nullable();
            $table->string('source', 64)->default('default');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'position']);
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_panels');
    }
};
