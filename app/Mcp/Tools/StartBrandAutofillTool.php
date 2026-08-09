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

#[Name('start_brand_autofill')]
#[Description('Queue brand website autofill (Firecrawl + NanoGPT; billable).')]
class StartBrandAutofillTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'website' => ['required', 'url', 'max:2048'],
        ]);

        $autofillId = (string) Str::uuid();
        Cache::put(AutofillBrandFromWebsiteJob::cacheKeyFor($user->id, $autofillId), [
            'status' => 'queued',
            'website' => $data['website'],
            'fields' => null,
            'error' => null,
        ], now()->addHour());

        AutofillBrandFromWebsiteJob::dispatch($user->id, $autofillId, $data['website']);

        return Response::json(['autofill_id' => $autofillId, 'queued' => true]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->required(),
        ];
    }
}
