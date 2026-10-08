<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        User::query()
            ->whereRaw('lower(email) = ?', ['danielsmyth098@gmail.com'])
            ->update(['is_admin' => true]);
    }

    public function down(): void
    {
        User::query()
            ->whereRaw('lower(email) = ?', ['danielsmyth098@gmail.com'])
            ->update(['is_admin' => false]);
    }
};
