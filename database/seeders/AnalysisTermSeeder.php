<?php

namespace Database\Seeders;

use App\Services\Analysis\AnalysisTermCatalogue;
use Illuminate\Database\Seeder;

class AnalysisTermSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $count = app(AnalysisTermCatalogue::class)->syncToDatabase();

        $this->command?->info("Synced {$count} analysis terms.");
    }
}
