<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Models\WinnerRule;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update winner scoring rules for the authenticated user.')]
class UpdateWinnerRulesTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'preset' => ['nullable', 'string', 'in:conservative,balanced,aggressive'],
            'min_engagement_rate' => ['nullable', 'numeric', 'min:0'],
            'min_views' => ['nullable', 'integer', 'min:0'],
            'min_likes' => ['nullable', 'integer', 'min:0'],
            'recency_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $preset = $data['preset'] ?? 'balanced';
        $defaults = (array) config("snitch.winners.presets.{$preset}", []);

        $rule = WinnerRule::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'preset' => $preset,
                'min_engagement_rate' => $data['min_engagement_rate'] ?? ($defaults['min_engagement_rate'] ?? 3),
                'min_views' => $data['min_views'] ?? ($defaults['min_views'] ?? 1000),
                'min_likes' => $data['min_likes'] ?? ($defaults['min_likes'] ?? 100),
                'recency_days' => $data['recency_days'] ?? ($defaults['recency_days'] ?? 30),
                'weights' => $defaults['weights'] ?? [
                    'views' => 0.4,
                    'likes' => 0.3,
                    'comments' => 0.2,
                    'shares' => 0.1,
                ],
            ],
        );

        return Response::json(['rules' => $rule->only([
            'preset', 'min_engagement_rate', 'min_views', 'min_likes', 'recency_days', 'weights',
        ])]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'preset' => $schema->string()->nullable(),
            'min_engagement_rate' => $schema->number()->nullable(),
            'min_views' => $schema->integer()->nullable(),
            'min_likes' => $schema->integer()->nullable(),
            'recency_days' => $schema->integer()->nullable(),
        ];
    }
}
