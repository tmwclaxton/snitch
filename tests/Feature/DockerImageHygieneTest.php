<?php

namespace Tests\Feature;

use Tests\TestCase;

class DockerImageHygieneTest extends TestCase
{
    public function test_production_dockerfile_does_not_copy_host_storage(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));
        $dockerignore = file_get_contents(base_path('.dockerignore'));

        $this->assertNotFalse($dockerfile);
        $this->assertNotFalse($dockerignore);
        $this->assertStringNotContainsString(
            'COPY --from=php_build /app/storage',
            $dockerfile,
            'Host storage (logs, inertia-devtools) must not be baked into the image',
        );
        $this->assertStringContainsString('storage/inertia-devtools', $dockerignore);
        $this->assertStringContainsString('node_modules', $dockerignore);
        $this->assertStringContainsString('.git', $dockerignore);
    }

    public function test_production_supervisord_uses_nginx_fpm_not_artisan_serve(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));
        $supervisord = file_get_contents(base_path('docker/production/supervisord.conf'));
        $web = file_get_contents(base_path('docker/production/web.conf'));

        $this->assertNotFalse($dockerfile);
        $this->assertNotFalse($supervisord);
        $this->assertNotFalse($web);
        $this->assertStringContainsString('docker/production/supervisord.conf', $dockerfile);
        $this->assertStringNotContainsString('SUPERVISOR_PHP_COMMAND', $supervisord);
        $this->assertStringNotContainsString('[program:php]', $supervisord);
        $this->assertStringContainsString('[program:php-fpm]', $web);
        $this->assertStringContainsString('[program:nginx]', $web);
        $this->assertStringContainsString("sed -i 's|^user .*|user sail;|' /etc/nginx/nginx.conf", $dockerfile);
    }
}
