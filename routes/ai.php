<?php

use App\Http\Middleware\AuthenticateMcp;
use App\Mcp\Servers\SnitchRegisterServer;
use App\Mcp\Servers\SnitchServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp/register', SnitchRegisterServer::class)
    ->middleware(['throttle:mcp-register']);

Mcp::web('/mcp', SnitchServer::class)
    ->middleware([AuthenticateMcp::class, 'throttle:mcp']);
