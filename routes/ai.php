<?php

use App\Mcp\Servers\SnitchRegisterServer;
use App\Mcp\Servers\SnitchServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/register', SnitchRegisterServer::class)
    ->middleware(['throttle:mcp-register']);

Mcp::web('/mcp', SnitchServer::class)
    ->middleware(['auth:sanctum', 'throttle:mcp']);
