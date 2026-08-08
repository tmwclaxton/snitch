<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeployScriptTest extends TestCase
{
    public function test_production_deploy_syncs_analysis_term_catalogue(): void
    {
        $script = file_get_contents(base_path('scripts/deploy-production.sh'));

        $this->assertNotFalse($script);
        $this->assertStringContainsString('php artisan migrate --force', $script);
        $this->assertStringContainsString(
            'php artisan db:seed --class=AnalysisTermSeeder --force',
            $script,
        );

        $migratePos = strpos($script, 'php artisan migrate --force');
        $seedPos = strpos($script, 'php artisan db:seed --class=AnalysisTermSeeder --force');

        $this->assertNotFalse($migratePos);
        $this->assertNotFalse($seedPos);
        $this->assertGreaterThan($migratePos, $seedPos);
    }
}
