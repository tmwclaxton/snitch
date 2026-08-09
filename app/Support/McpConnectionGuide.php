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
                    'blurb' => 'Add Snitch under Cursor Settings → MCP, or merge into your project mcp.json.',
                    'steps' => [
                        'Create or rotate an API token on the Agents page (or call create_account on the register endpoint).',
                        'Open Cursor Settings → MCP.',
                        'Add a server named snitch with the URL and Authorization header below.',
                        'Reload MCP servers, then call whoami to confirm.',
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
                    'name' => 'Claude',
                    'blurb' => 'Works with Claude Desktop / Claude Code HTTP MCP configs that accept bearer headers.',
                    'steps' => [
                        'Create a Snitch API token.',
                        'Add an HTTP MCP server pointing at the Snitch MCP URL.',
                        'Pass Authorization: Bearer YOUR_SNITCH_API_TOKEN.',
                        'Restart Claude and run whoami.',
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
                    'blurb' => 'Use any Codex / OpenAI agent harness that can attach a remote MCP server over HTTP.',
                    'steps' => [
                        'Create a Snitch API token.',
                        'Register a remote MCP server with the Snitch URL.',
                        'Attach the bearer token in request headers.',
                        'Prefer tool calls like list_competitors, find_influencers, and analyze_post.',
                    ],
                    'snippet' => implode("\n", [
                        'MCP server URL: '.$mcpUrl,
                        'Auth header: Authorization: Bearer YOUR_SNITCH_API_TOKEN',
                        '',
                        'Register (no auth): '.$registerUrl,
                        'Tool: create_account',
                    ]),
                ],
                [
                    'id' => 'windsurf',
                    'name' => 'Windsurf',
                    'blurb' => 'Same HTTP + bearer pattern as Cursor for Windsurf MCP settings.',
                    'steps' => [
                        'Create a Snitch API token.',
                        'Open Windsurf MCP settings.',
                        'Add snitch with URL and Authorization header.',
                        'Verify with whoami.',
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
                'blurb' => 'Any MCP client that speaks Streamable HTTP / JSON-RPC over HTTPS can connect.',
                'steps' => [
                    'Register at '.$registerUrl.' with create_account, or mint a token on the website Agents page.',
                    'Claim the account in the browser if an agent created it (claim URL is returned).',
                    'Subscribe to the platform plan and top up credits before billable tools.',
                    'Call authenticated tools on '.$mcpUrl.' with Authorization: Bearer <token>.',
                ],
                'snippet' => implode("\n", [
                    'Register (public): '.$registerUrl,
                    '  tools/call → create_account',
                    '',
                    'Authenticated: '.$mcpUrl,
                    '  Header: Authorization: Bearer YOUR_SNITCH_API_TOKEN',
                    '',
                    'Useful first calls: whoami, billing_status, update_brand, add_competitor, find_influencers',
                ]),
            ],
            'tools' => [
                'create_account', 'whoami', 'claim_info', 'rotate_token',
                'billing_status', 'create_platform_checkout', 'create_credit_checkout', 'billing_portal',
                'get_brand', 'update_brand', 'start_brand_autofill', 'autofill_status',
                'list_competitors', 'add_competitor', 'remove_competitor', 'sync_competitor',
                'suggest_competitors', 'suggest_competitors_status', 'confirm_competitor_suggestions', 'dismiss_competitor_suggestions',
                'generate_influencer_brief', 'find_influencers', 'influencer_search_status', 'keep_influencer', 'discard_influencer',
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
