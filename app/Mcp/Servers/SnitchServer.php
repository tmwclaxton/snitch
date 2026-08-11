<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\WorkflowGuidePrompt;
use App\Mcp\Tools\AddCompetitorTool;
use App\Mcp\Tools\AnalyzePostTool;
use App\Mcp\Tools\AutofillStatusTool;
use App\Mcp\Tools\BillingPortalTool;
use App\Mcp\Tools\BillingStatusTool;
use App\Mcp\Tools\ClaimInfoTool;
use App\Mcp\Tools\ConfirmCompetitorSuggestionsTool;
use App\Mcp\Tools\CreateCreditCheckoutTool;
use App\Mcp\Tools\CreatePlatformCheckoutTool;
use App\Mcp\Tools\DiscardInfluencerTool;
use App\Mcp\Tools\DismissCompetitorSuggestionsTool;
use App\Mcp\Tools\ExplorePostsTool;
use App\Mcp\Tools\FindInfluencersTool;
use App\Mcp\Tools\GenerateInfluencerBriefTool;
use App\Mcp\Tools\GetBrandTool;
use App\Mcp\Tools\GetPostTool;
use App\Mcp\Tools\InfluencerSearchStatusTool;
use App\Mcp\Tools\KeepInfluencerTool;
use App\Mcp\Tools\ListCompetitorsTool;
use App\Mcp\Tools\ListFeedTool;
use App\Mcp\Tools\ListInfluencersTool;
use App\Mcp\Tools\ListWinnersTool;
use App\Mcp\Tools\RemoveCompetitorTool;
use App\Mcp\Tools\RemoveInfluencerTool;
use App\Mcp\Tools\RescoreWinnersStatusTool;
use App\Mcp\Tools\RescoreWinnersTool;
use App\Mcp\Tools\RotateTokenTool;
use App\Mcp\Tools\StartBrandAutofillTool;
use App\Mcp\Tools\SuggestCompetitorsStatusTool;
use App\Mcp\Tools\SuggestCompetitorsTool;
use App\Mcp\Tools\SyncCompetitorTool;
use App\Mcp\Tools\UpdateBrandTool;
use App\Mcp\Tools\UpdateWinnerRulesTool;
use App\Mcp\Tools\WhoamiTool;
use App\Mcp\Tools\WorkflowGuideTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Snitch')]
#[Version('1.0.0')]
#[Instructions('Snitch is the social marketing data layer for agents. Authenticate with a Sanctum bearer token from create_account (or the website Agents page). Call workflow_guide first (workflow=overview or a specific flow), then whoami: check runtime.app_url (local vs https://www.snitchsocial.net), brand_warnings, and queue warnings. Local MCP (localhost) uses a different database and credits than production. Async tools need php artisan queue:work. Billable tools need a credit balance above 20p - subscribe for monthly plan value or top up credits. Prefer sync/analyze/find tools explicitly - nothing is auto-scheduled. Never paste bearer tokens into public chats; prefer rotate_token after exposure. Snitch discovery must complete the full loop: suggest_competitors → poll suggest_competitors_status → confirm_competitor_suggestions with selected handles (or dismiss_competitor_suggestions). Suggestions are cache-only until confirmed; they are NOT tracked snitches. Influencer discovery is the same pattern: find_influencers → influencer_search_status → keep_influencer / discard_influencer. Fix brand_warnings before discovery so suggestions match the intended company.')]
class SnitchServer extends Server
{
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        WorkflowGuideTool::class,
        WhoamiTool::class,
        ClaimInfoTool::class,
        RotateTokenTool::class,
        BillingStatusTool::class,
        CreatePlatformCheckoutTool::class,
        CreateCreditCheckoutTool::class,
        BillingPortalTool::class,
        GetBrandTool::class,
        UpdateBrandTool::class,
        StartBrandAutofillTool::class,
        AutofillStatusTool::class,
        ListCompetitorsTool::class,
        AddCompetitorTool::class,
        RemoveCompetitorTool::class,
        SyncCompetitorTool::class,
        SuggestCompetitorsTool::class,
        SuggestCompetitorsStatusTool::class,
        ConfirmCompetitorSuggestionsTool::class,
        DismissCompetitorSuggestionsTool::class,
        GenerateInfluencerBriefTool::class,
        FindInfluencersTool::class,
        InfluencerSearchStatusTool::class,
        KeepInfluencerTool::class,
        DiscardInfluencerTool::class,
        RemoveInfluencerTool::class,
        ListInfluencersTool::class,
        ListFeedTool::class,
        GetPostTool::class,
        AnalyzePostTool::class,
        ListWinnersTool::class,
        UpdateWinnerRulesTool::class,
        RescoreWinnersTool::class,
        RescoreWinnersStatusTool::class,
        ExplorePostsTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [
        WorkflowGuidePrompt::class,
    ];
}
