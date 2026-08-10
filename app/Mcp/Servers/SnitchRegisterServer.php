<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateAccountTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Snitch Register')]
#[Version('1.0.0')]
#[Instructions('Create a Snitch agent account and receive an API token. Humans claim the account at the returned claim URL. Billable tools need a credit balance above 20p after claim/top-up or a platform plan.')]
class SnitchRegisterServer extends Server
{
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        CreateAccountTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
