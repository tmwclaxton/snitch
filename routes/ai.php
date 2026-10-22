<?php

use App\Http\Middleware\AuthenticateMcp;
use App\Http\Middleware\EnsureMcpProductAccess;
use App\Http\Middleware\LogAutomationMcpCall;
use App\Http\Middleware\LogCuratorMcpAudit;
use App\Http\Middleware\LogMcpToolInvocation;
use App\Http\Middleware\RejectAdminMcpTools;
use App\Mcp\Servers\SnitchRegisterServer;
use App\Mcp\Servers\SnitchServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp/register', SnitchRegisterServer::class)
    ->middleware(['throttle:mcp-register']);

Mcp::web('/mcp', SnitchServer::class)
    ->middleware([
        AuthenticateMcp::class,
        LogAutomationMcpCall::class,
        LogMcpToolInvocation::class,
        LogCuratorMcpAudit::class,
        RejectAdminMcpTools::class,
        EnsureMcpProductAccess::class,
        'throttle:mcp',
    ]);
