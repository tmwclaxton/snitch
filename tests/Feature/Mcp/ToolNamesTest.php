<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SnitchRegisterServer;
use App\Mcp\Servers\SnitchServer;
use App\Support\McpConnectionGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_tool_names_match_connection_guide(): void
    {
        $expected = McpConnectionGuide::payload()['tools'];

        $registerNames = $this->toolNames(SnitchRegisterServer::class);
        $authNames = $this->toolNames(SnitchServer::class);
        $actual = [...$registerNames, ...$authNames];

        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
        $this->assertNotContains('create-account-tool', $actual);
        $this->assertContains('create_account', $actual);
        $this->assertContains('whoami', $actual);
    }

    /**
     * @param  class-string  $serverClass
     * @return list<string>
     */
    private function toolNames(string $serverClass): array
    {
        $reflection = new \ReflectionClass($serverClass);
        $property = $reflection->getProperty('tools');
        /** @var list<class-string> $toolClasses */
        $toolClasses = $property->getDefaultValue();

        return array_map(
            fn (string $class): string => app($class)->name(),
            $toolClasses,
        );
    }
}
