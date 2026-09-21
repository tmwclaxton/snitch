<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follower_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('followers');
            $table->date('captured_on');
            $table->timestamps();

            $table->unique(['social_account_id', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follower_snapshots');
    }
};
