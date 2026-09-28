<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Models\WinnerRule;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('update_winner_rules')]
#[Description('Update winner scoring rules for the authenticated user. Threshold is relative (min_multiplier × usual).')]
class UpdateWinnerRulesTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'preset' => ['nullable', 'string', 'in:gentle,balanced,strict'],
            'min_multiplier' => ['nullable', 'numeric', 'min:1', 'max:20'],
            'recency_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $preset = $data['preset'] ?? 'balanced';
        $defaults = (array) config("snitch.winners.presets.{$preset}", []);

        $rule = WinnerRule::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'preset' => $preset,
                'min_multiplier' => $data['min_multiplier'] ?? ($defaults['min_multiplier'] ?? 2.0),
                'min_engagement_rate' => $defaults['min_engagement_rate'] ?? 0,
                'min_views' => $defaults['min_views'] ?? 0,
                'min_likes' => $defaults['min_likes'] ?? 0,
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
            'preset', 'min_multiplier', 'recency_days', 'weights',
        ])]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'preset' => $schema->string()->nullable(),
            'min_multiplier' => $schema->number()->nullable(),
            'recency_days' => $schema->integer()->nullable(),
        ];
    }
}
