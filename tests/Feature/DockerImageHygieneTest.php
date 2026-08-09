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
}
