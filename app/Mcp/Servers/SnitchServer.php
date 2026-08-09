<?php

namespace App\Mcp\Servers;

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
use App\Mcp\Tools\ListWinnersTool;
use App\Mcp\Tools\RemoveCompetitorTool;
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
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Snitch')]
#[Version('1.0.0')]
#[Instructions('Snitch is the social marketing data layer for agents. Authenticate with a Sanctum bearer token from create_account (or the website Agents page). Billable tools require an active platform subscription and usage credits. Prefer sync/analyze/find tools explicitly - nothing is auto-scheduled.')]
class SnitchServer extends Server
{
    public int $defaultPaginationLength = 50;

    protected array $tools = [
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

    protected array $prompts = [];
}
