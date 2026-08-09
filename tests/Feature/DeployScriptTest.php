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

    public function test_production_deploy_retries_ghcr_login_and_pull_with_backoff(): void
    {
        $script = file_get_contents(base_path('scripts/deploy-production.sh'));

        $this->assertNotFalse($script);
        $this->assertStringContainsString('retry_with_backoff', $script);
        $this->assertStringContainsString('ghcr_login', $script);
        $this->assertStringContainsString('pull_app_image', $script);
        $this->assertStringContainsString('prefer_ipv4_egress', $script);
        $this->assertStringContainsString('precedence :ffff:0:0/96', $script);
        $this->assertStringContainsString('flock -n 9', $script);
        $this->assertStringContainsString('PULL_TIMEOUT_SECONDS', $script);
        $this->assertStringContainsString('timeout "${PULL_TIMEOUT_SECONDS}"', $script);
        $this->assertStringContainsString('docker pull "$APP_IMAGE"', $script);
        $this->assertStringContainsString('retry_with_backoff "$GHCR_MAX_ATTEMPTS" ghcr_login', $script);
        $this->assertStringContainsString('retry_with_backoff "$GHCR_MAX_ATTEMPTS" pull_app_image', $script);
        $this->assertStringContainsString('SKIP_GHCR_PULL', $script);
        $this->assertStringContainsString('compose up -d --pull never', $script);

        $workflow = file_get_contents(base_path('.github/workflows/prod_deploy.yml'));

        $this->assertNotFalse($workflow);
        $this->assertStringContainsString('concurrency:', $workflow);
        $this->assertStringContainsString('group: production-deploy', $workflow);
        $this->assertStringContainsString('cancel-in-progress: false', $workflow);
        $this->assertStringContainsString('ControlMaster auto', $workflow);
        $this->assertStringContainsString('AddressFamily inet', $workflow);
        $this->assertStringContainsString('Open SSH master connection', $workflow);
        $this->assertStringContainsString('retry_with_backoff 8 open_master', $workflow);
        $this->assertStringContainsString('retry_with_backoff 5 copy_deploy_files', $workflow);
        $this->assertStringContainsString('retry_with_backoff 4 ssh', $workflow);
        $this->assertStringContainsString('Transfer image to server', $workflow);
        $this->assertStringContainsString('docker save', $workflow);
        $this->assertStringContainsString('docker load', $workflow);
        $this->assertStringContainsString('SKIP_GHCR_PULL=1 ./deploy-production.sh', $workflow);
        $this->assertStringContainsString('Close SSH master connection', $workflow);
    }
}
