<?php

namespace App\Mcp\Tools;

use App\Jobs\AutofillBrandFromWebsiteJob;
use App\Mcp\Support\McpAuth;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('autofill_status')]
#[Description('Poll brand website autofill status.')]
class AutofillStatusTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'autofill_id' => ['required', 'string'],
        ]);

        if (! Str::isUuid($data['autofill_id'])) {
            return Response::error('Invalid autofill_id.');
        }

        $payload = Cache::get(AutofillBrandFromWebsiteJob::cacheKeyFor($user->id, $data['autofill_id']));

        return Response::json([
            'autofill_id' => $data['autofill_id'],
            'payload' => $payload,
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'autofill_id' => $schema->string()->required(),
        ];
    }
}
