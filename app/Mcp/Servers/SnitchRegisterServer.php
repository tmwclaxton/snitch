<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateAccountTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Snitch Register')]
#[Version('1.0.0')]
#[Instructions('Create a Snitch agent account and receive an API token. Humans claim the account at the returned claim URL. No free usage until claimed and subscribed.')]
class SnitchRegisterServer extends Server
{
    protected array $tools = [
        CreateAccountTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
