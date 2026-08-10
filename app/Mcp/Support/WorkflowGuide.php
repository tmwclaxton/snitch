<?php

namespace App\Mcp\Support;

final class WorkflowGuide
{
    public const WORKFLOWS = [
        'overview',
        'brand',
        'competitors',
        'influencers',
        'sync_analyze',
        'billing',
        'explore',
    ];

    /**
     * @return array{
     *     workflow: string,
     *     summary: string,
     *     prerequisites: list<string>,
     *     do_not_skip: list<string>,
     *     steps: list<array{order: int, tool: string, action: string}>,
     *     notes: list<string>,
     *     available_workflows: list<string>
     * }
     */
    public static function for(string $workflow = 'overview'): array
    {
        $key = in_array($workflow, self::WORKFLOWS, true) ? $workflow : 'overview';

        $guide = match ($key) {
            'brand' => self::brand(),
            'competitors' => self::competitors(),
            'influencers' => self::influencers(),
            'sync_analyze' => self::syncAnalyze(),
            'billing' => self::billing(),
            'explore' => self::explore(),
            default => self::overview(),
        };

        return [
            ...$guide,
            'workflow' => $key,
            'available_workflows' => self::WORKFLOWS,
        ];
    }

    /**
     * @return array{
     *     summary: string,
     *     prerequisites: list<string>,
     *     do_not_skip: list<string>,
     *     steps: list<array{order: int, tool: string, action: string}>,
     *     notes: list<string>
     * }
     */
    private static function overview(): array
    {
        return [
            'summary' => 'Call workflow_guide with a specific workflow before discovery or billable work. Always start sessions with whoami + billing_status.',
            'prerequisites' => [
                'Sanctum bearer token (Agents page or create_account on /mcp/register).',
                'Local async jobs need php artisan queue:work (or composer run dev).',
            ],
            'do_not_skip' => [
                'whoami first - check runtime.app_url (local vs https://www.snitchsocial.net) and brand_warnings.',
                'Competitor/influencer discovery is incomplete until confirm/keep.',
            ],
            'steps' => [
                self::step(1, 'whoami', 'Confirm user, runtime.app_url, brand_warnings, queue warnings.'),
                self::step(2, 'billing_status', 'Confirm can_run_billable (balance above 20p) before sync/suggest/find/analyze.'),
                self::step(3, 'get_brand', 'Verify name + website; fix with update_brand or start_brand_autofill → autofill_status.'),
                self::step(4, 'workflow_guide', 'Pick brand | competitors | influencers | sync_analyze | billing | explore and follow that guide.'),
            ],
            'notes' => [
                'Localhost and production are different databases and credit balances.',
                'Nothing is auto-scheduled - agents/users trigger sync, suggest, find, analyze, winners.',
                'On local artisan serve prefer short wait_seconds (8-12) and re-poll status tools - long waits stall the browser UI.',
                'Never paste bearer tokens into public chats; use rotate_token if exposed.',
            ],
        ];
    }

    /**
     * @return array{
     *     summary: string,
     *     prerequisites: list<string>,
     *     do_not_skip: list<string>,
     *     steps: list<array{order: int, tool: string, action: string}>,
     *     notes: list<string>
     * }
     */
    private static function brand(): array
    {
        return [
            'summary' => 'Ensure brand name + website are set before competitor or influencer discovery.',
            'prerequisites' => [
                'whoami already called.',
            ],
            'do_not_skip' => [
                'Brand name and website are required for suggest_competitors and find_influencers.',
                'Empty description warns only; blank website/name hard-blocks discovery.',
                'Switching brand does NOT clear tracked competitors/influencers - remove prior-niche accounts before discovery.',
            ],
            'steps' => [
                self::step(1, 'whoami', 'Read brand_warnings.'),
                self::step(2, 'get_brand', 'Inspect current brand profile.'),
                self::step(3, 'update_brand', 'Set name, website, description when you already know them.'),
                self::step(4, 'start_brand_autofill', 'Pass website; optional wait_seconds. Then autofill_status until completed (autofill_id optional - omit for latest).'),
                self::step(5, 'list_competitors / list_influencers', 'After a brand switch, remove prior-niche tracked accounts with remove_competitor / remove_influencer.'),
                self::step(6, 'get_brand', 'Confirm autofill persisted; tweak with update_brand if needed.'),
            ],
            'notes' => [
                'Soft-warn when name looks unrelated to the website host - fix before discovery.',
                'Autofill is billable (Firecrawl/NanoGPT) and needs queue workers locally.',
                'One Sanctum user shares competitors/influencers across brand switches - clean up explicitly.',
            ],
        ];
    }

    /**
     * @return array{
     *     summary: string,
     *     prerequisites: list<string>,
     *     do_not_skip: list<string>,
     *     steps: list<array{order: int, tool: string, action: string}>,
     *     notes: list<string>
     * }
     */
    private static function competitors(): array
    {
        return [
            'summary' => 'Discover competitors, then confirm selections. Suggestions are cache-only until confirmed.',
            'prerequisites' => [
                'whoami + billing_status (can_run_billable).',
                'Brand name + website ready (BrandContext).',
                'Queue worker running for local async.',
            ],
            'do_not_skip' => [
                'After suggest completes you MUST call confirm_competitor_suggestions or dismiss_competitor_suggestions.',
                'Completed suggest_competitors_status is NOT tracked competitors yet.',
                'Prefer waiting until status=completed before confirming - mid-run rows can include weak/off-niche matches.',
            ],
            'steps' => [
                self::step(1, 'whoami', 'Check brand_warnings and runtime.'),
                self::step(2, 'billing_status', 'Ensure balance above 20p.'),
                self::step(3, 'get_brand', 'Confirm name + website before suggest.'),
                self::step(4, 'suggest_competitors', 'Queue suggestions; on local serve use wait_seconds 8-12 then re-poll status.'),
                self::step(5, 'suggest_competitors_status', 'Poll until completed/failed (suggest_id optional - omit for latest/active run). On local serve use wait_seconds 8-12.'),
                self::step(6, 'confirm_competitor_suggestions', 'Pass suggest_id + selected handles (strings or {platform, handle}). Default dismiss_remainder=true. Or dismiss_competitor_suggestions + add_competitor when suggestions are off-niche.'),
                self::step(7, 'list_competitors', 'Verify tracked accounts. Use dismiss_competitor_suggestions to clear without tracking.'),
            ],
            'notes' => [
                'add_competitor / remove_competitor for manual edits; sync_competitor after tracking.',
                'Do not end the session with unconfirmed suggestions still pending.',
                'Partial suggestions stream while processing - cherry-pick carefully or wait for completed.',
                'Brand switch does not auto-clear rivals - remove_competitor for prior niche first.',
            ],
        ];
    }

    /**
     * @return array{
     *     summary: string,
     *     prerequisites: list<string>,
     *     do_not_skip: list<string>,
     *     steps: list<array{order: int, tool: string, action: string}>,
     *     notes: list<string>
     * }
     */
    private static function influencers(): array
    {
        return [
            'summary' => 'Find influencer candidates, then keep or discard. Find alone does not keep anyone.',
            'prerequisites' => [
                'whoami + billing_status (can_run_billable).',
                'Brand name + website ready.',
                'Queue worker running for local async.',
            ],
            'do_not_skip' => [
                'After find completes you MUST keep_influencer and/or discard_influencer before ending.',
                'Suggestions are not kept accounts until keep_influencer succeeds.',
            ],
            'steps' => [
                self::step(1, 'whoami', 'Check brand_warnings and runtime.'),
                self::step(2, 'billing_status', 'Ensure balance above 20p.'),
                self::step(3, 'generate_influencer_brief', 'Optional; persist brief from brand context.'),
                self::step(4, 'find_influencers', 'Pass platform + brief; on local serve use wait_seconds 8-12 then re-poll.'),
                self::step(5, 'influencer_search_status', 'Poll run_id (or omit to use latest) until completed/failed.'),
                self::step(6, 'keep_influencer', 'Keep selected rows (platform + handle; include run_id when available).'),
                self::step(7, 'discard_influencer', 'Discard rejects. Then list_influencers to verify kept set.'),
                self::step(8, 'remove_influencer', 'Optional: delete a kept tracked influencer (id / influencer_id / tracked_account_id).'),
            ],
            'notes' => [
                'fit_reason and url appear on suggestions - use them when choosing keep vs discard.',
                'A new find_influencers call replaces the latest pointer - always poll the run_id you were given (or omit run_id to follow latest).',
                'discard_influencer only clears suggestion cache; remove_influencer deletes a kept tracked account.',
            ],
        ];
    }

    /**
     * @return array{
     *     summary: string,
     *     prerequisites: list<string>,
     *     do_not_skip: list<string>,
     *     steps: list<array{order: int, tool: string, action: string}>,
     *     notes: list<string>
     * }
     */
    private static function syncAnalyze(): array
    {
        return [
            'summary' => 'Sync tracked accounts, read the feed, analyze posts, optionally score winners.',
            'prerequisites' => [
                'At least one tracked competitor/influencer.',
                'billing_status can_run_billable before sync/analyze/rescore.',
                'Queue worker for local async.',
            ],
            'do_not_skip' => [
                'Assert credits before sync - blocked sync must not look like Syncing.',
                'Prefer analyze_post on specific posts; winners rescore is explicit too.',
            ],
            'steps' => [
                self::step(1, 'list_competitors', 'Pick tracked_account_id (or list_influencers).'),
                self::step(2, 'billing_status', 'Confirm can_run_billable.'),
                self::step(3, 'sync_competitor', 'Force-sync posts for that account (billable).'),
                self::step(4, 'list_feed', 'Browse recent posts; get_post for detail.'),
                self::step(5, 'analyze_post', 'Analyze selected post_id (billable).'),
                self::step(6, 'list_winners', 'Optional: update_winner_rules then rescore_winners → rescore_winners_status.'),
            ],
            'notes' => [
                'Manual/MCP sync always force-runs; ops due-interval does not apply.',
            ],
        ];
    }

    /**
     * @return array{
     *     summary: string,
     *     prerequisites: list<string>,
     *     do_not_skip: list<string>,
     *     steps: list<array{order: int, tool: string, action: string}>,
     *     notes: list<string>
     * }
     */
    private static function billing(): array
    {
        return [
            'summary' => 'Check credits, subscribe for plan value, or top up before billable tools.',
            'prerequisites' => [
                'Authenticated MCP session.',
            ],
            'do_not_skip' => [
                'Billable tools need balance strictly above 20p.',
                'Agent create_account starts at £0 until claim/subscribe/top-up.',
            ],
            'steps' => [
                self::step(1, 'whoami', 'See subscription summary + runtime.'),
                self::step(2, 'billing_status', 'Read balance, can_run_billable, vendor usage.'),
                self::step(3, 'create_platform_checkout', '£19/mo platform plan (includes periodic usage credits).'),
                self::step(4, 'create_credit_checkout', 'Top up a credit pack when balance is low.'),
                self::step(5, 'billing_portal', 'Open Stripe portal for existing customers.'),
                self::step(6, 'claim_info', 'If agent-created: claim URL for browser bind (+ claim bonus once).'),
            ],
            'notes' => [
                'UI/MCP show charged GBP only - never markup or COGS.',
                'Local vs production balances are separate.',
            ],
        ];
    }

    /**
     * @return array{
     *     summary: string,
     *     prerequisites: list<string>,
     *     do_not_skip: list<string>,
     *     steps: list<array{order: int, tool: string, action: string}>,
     *     notes: list<string>
     * }
     */
    private static function explore(): array
    {
        return [
            'summary' => 'Search and open explore posts; searches and non-competitor views cost small product fees.',
            'prerequisites' => [
                'billing_status can_run_billable for paid explore.search / explore.view.',
            ],
            'do_not_skip' => [
                'explore_posts with q charges explore.search (idempotent per normalised query).',
                'Opening a completed reel not from your tracked competitors charges explore.view.',
            ],
            'steps' => [
                self::step(1, 'billing_status', 'Confirm credits.'),
                self::step(2, 'explore_posts', 'Filter/search; pass q for search. Use post_id to open a reel.'),
                self::step(3, 'get_post', 'Optional deeper post payload after explore.'),
                self::step(4, 'analyze_post', 'Optional analysis on a chosen post.'),
            ],
            'notes' => [
                'Tracked-competitor views are free for explore.view.',
            ],
        ];
    }

    /**
     * @return array{order: int, tool: string, action: string}
     */
    private static function step(int $order, string $tool, string $action): array
    {
        return [
            'order' => $order,
            'tool' => $tool,
            'action' => $action,
        ];
    }
}
