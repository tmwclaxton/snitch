<?php

namespace App\Support;

class McpConnectionGuide
{
    /**
     * @return array{
     *     mcp_url: string,
     *     register_url: string,
     *     clients: list<array{id: string, name: string, blurb: string, snippet: string, steps: list<string>}>,
     *     general: array{title: string, blurb: string, snippet: string, steps: list<string>},
     *     tools: list<string>
     * }
     */
    public static function payload(?string $absoluteOrigin = null): array
    {
        $origin = rtrim($absoluteOrigin ?: (string) config('app.url'), '/');
        $mcpUrl = $origin.'/mcp';
        $registerUrl = $origin.'/mcp/register';

        return [
            'mcp_url' => $mcpUrl,
            'register_url' => $registerUrl,
            'clients' => [
                [
                    'id' => 'cursor',
                    'name' => 'Cursor',
                    'blurb' => 'Add Snitch in Cursor Settings → MCP, or merge into your project mcp.json.',
                    'steps' => [
                        'Mint an API token on the Agents page.',
                        'Open Cursor Settings → MCP and add snitch with the config below.',
                        'Reload MCP servers, then call whoami.',
                    ],
                    'snippet' => self::jsonSnippet([
                        'mcpServers' => [
                            'snitch' => [
                                'url' => $mcpUrl,
                                'headers' => [
                                    'Authorization' => 'Bearer YOUR_SNITCH_API_TOKEN',
                                ],
                            ],
                        ],
                    ]),
                ],
                [
                    'id' => 'claude',
                    'name' => 'Claude.ai',
                    'blurb' => 'Custom connector with OAuth - paste the URL, sign in when prompted.',
                    'steps' => [
                        'In Claude.ai: Settings → Connectors → Add custom connector.',
                        'Paste the Snitch MCP URL below and complete sign-in when prompted.',
                        'Call whoami to confirm.',
                    ],
                    'snippet' => implode("\n", [
                        'Claude.ai custom connector URL:',
                        $mcpUrl,
                    ]),
                ],
                [
                    'id' => 'claude-desktop',
                    'name' => 'Claude Desktop / Claude Code',
                    'blurb' => 'HTTP MCP with a bearer token, same pattern as Cursor.',
                    'steps' => [
                        'Mint an API token on the Agents page.',
                        'Add an HTTP MCP server with the config below.',
                        'Restart Claude and call whoami.',
                    ],
                    'snippet' => self::jsonSnippet([
                        'mcpServers' => [
                            'snitch' => [
                                'type' => 'http',
                                'url' => $mcpUrl,
                                'headers' => [
                                    'Authorization' => 'Bearer YOUR_SNITCH_API_TOKEN',
                                ],
                            ],
                        ],
                    ]),
                ],
                [
                    'id' => 'codex',
                    'name' => 'Codex',
                    'blurb' => 'Any Codex or OpenAI agent harness that supports remote HTTP MCP.',
                    'steps' => [
                        'Mint an API token on the Agents page.',
                        'Register the Snitch MCP URL with the bearer header below.',
                        'Call whoami, then workflow_guide for tool order.',
                    ],
                    'snippet' => implode("\n", [
                        'MCP server URL: '.$mcpUrl,
                        'Auth header: Authorization: Bearer YOUR_SNITCH_API_TOKEN',
                    ]),
                ],
                [
                    'id' => 'windsurf',
                    'name' => 'Windsurf',
                    'blurb' => 'Same HTTP + bearer pattern as Cursor.',
                    'steps' => [
                        'Mint an API token on the Agents page.',
                        'Open Windsurf MCP settings and add snitch with the config below.',
                        'Call whoami to confirm.',
                    ],
                    'snippet' => self::jsonSnippet([
                        'mcpServers' => [
                            'snitch' => [
                                'serverUrl' => $mcpUrl,
                                'headers' => [
                                    'Authorization' => 'Bearer YOUR_SNITCH_API_TOKEN',
                                ],
                            ],
                        ],
                    ]),
                ],
            ],
            'general' => [
                'title' => 'General MCP',
                'blurb' => 'Any MCP client over HTTPS can connect to production Snitch.',
                'steps' => [
                    'Mint an API token on the Agents page, or call create_account on the register endpoint.',
                    'Paste the config into your MCP client (see tabs above).',
                    'Call whoami to confirm the connection.',
                    'If an agent created the account, open the claim URL in your browser.',
                    'Keep a balance above 20p before billable tools - subscribe for plan credits or top up.',
                ],
                'snippet' => implode("\n", [
                    'Register (public): '.$registerUrl,
                    '  Tool: create_account',
                    '',
                    'Authenticated: '.$mcpUrl,
                    '  Header: Authorization: Bearer YOUR_SNITCH_API_TOKEN',
                    '',
                    'Starter calls: workflow_guide, whoami, billing_status, get_brand',
                ]),
            ],
            'tools' => [
                'create_account', 'workflow_guide', 'whoami', 'claim_info', 'rotate_token',
                'billing_status', 'create_platform_checkout', 'create_credit_checkout', 'billing_portal',
                'get_brand', 'update_brand', 'start_brand_autofill', 'autofill_status',
                'list_competitors', 'add_competitor', 'remove_competitor', 'sync_competitor',
                'suggest_competitors', 'suggest_competitors_status', 'confirm_competitor_suggestions', 'dismiss_competitor_suggestions',
                'generate_influencer_brief', 'find_influencers', 'influencer_search_status', 'list_influencers', 'keep_influencer', 'discard_influencer', 'remove_influencer',
                'list_feed', 'get_post', 'analyze_post',
                'list_winners', 'update_winner_rules', 'rescore_winners', 'rescore_winners_status',
                'explore_posts',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function jsonSnippet(array $payload): string
    {
        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
