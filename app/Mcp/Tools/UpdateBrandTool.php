<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Models\BrandProfile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('update_brand')]
#[Description('Create or update the brand profile for the authenticated user.')]
class UpdateBrandTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'website' => ['nullable', 'string', 'max:255'],
            'own_handles' => ['nullable', 'array'],
        ]);

        $brand = BrandProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'website' => $data['website'] ?? null,
                'own_handles' => $data['own_handles'] ?? [],
            ],
        );

        return Response::json(['brand' => $brand->only(['id', 'name', 'description', 'website', 'own_handles'])]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required(),
            'description' => $schema->string()->nullable(),
            'website' => $schema->string()->nullable(),
            'own_handles' => $schema->object()->nullable(),
        ];
    }
}
