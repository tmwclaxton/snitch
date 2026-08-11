# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and read that rule file.

| Applies to | Rule file |
| --- | --- |
| app/Services/Apify/Adapters/YoutubeAdapter.php, app/Services/TikHub/Adapters/YoutubeAdapter.php, app/Services/Scraping/YoutubeMediaHydrator.php | .ai/rules/adapters.md |
| app/Services/Analysis/**, database/data/analysis_terms.php, app/Http/Controllers/ExploreController.php, resources/js/pages/explore/** | .ai/rules/analysis.md |
| app/Services/Apify/**, app/Services/TikHub/**, app/Services/Scraping/** | .ai/rules/apify.md |
| app/Services/Billing/**, app/Http/Controllers/Settings/BillingController.php, app/Http/Controllers/ClaimController.php, app/Http/Controllers/AgentsController.php, app/Http/Controllers/Marketing/PricingController.php, app/Listeners/HandleStripeWebhook.php, app/Policies/TrackedAccountPolicy.php, app/Http/Controllers/CompetitorController.php, app/Http/Controllers/InfluencerController.php, app/Mcp/**, app/Support/McpConnectionGuide.php, routes/ai.php, config/billing.php, config/subscriptions.php, config/cashier.php, resources/js/pages/billing/**, resources/js/pages/marketing/Pricing.vue, resources/js/pages/marketing/Agents.vue, resources/js/pages/agents/**, resources/js/components/agents/**, resources/js/pages/claim/** | .ai/rules/billing.md |
| app/Http/Controllers/Admin/**, app/Http/Middleware/EnsureAdmin.php, app/Http/Middleware/LogMcpToolInvocation.php, app/Services/Admin/**, app/Models/McpToolInvocation.php, resources/js/pages/admin/**, routes/web.php, routes/ai.php, config/snitch.php | .ai/rules/admin.md |
| bootstrap/app.php, app/Providers/AppServiceProvider.php, app/Services/Apify/**, app/Jobs/SyncTrackedAccountJob.php, app/Support/ClientIp.php, app/Support/SafeExceptionMessage.php | .ai/rules/security-proxies-secrets.md |
| app/Console/Commands/** | .ai/rules/commands.md |
| app/Services/SnitchAnalyticsService.php, app/Support/AnalyticsDateRange.php, app/Http/Controllers/AnalyticsController.php, app/Http/Requests/AnalyticsPeriodRequest.php, app/Console/Commands/BackfillAnalyticsCommand.php, app/Models/SnitchDailyStat.php, app/Models/SnitchDailyPlatformStat.php, resources/js/pages/marketing/Analytics.vue, resources/js/components/analytics/** | .ai/rules/analytics.md |
| app/Services/Competitors/**, resources/js/pages/competitors/**, app/Http/Controllers/CompetitorController.php | .ai/rules/competitors.md |
| app/Services/Influencers/**, app/Http/Controllers/InfluencerController.php, app/Http/Requests/Influencers/**, app/Jobs/FindInfluencersJob.php, app/Jobs/GenerateInfluencerBriefJob.php, app/Http/Controllers/OnboardingController.php, app/Console/Commands/ProbeInfluencerFindCommand.php, app/Enums/TrackedAccountKind.php, app/Mcp/Tools/*Influencer*, resources/js/pages/influencers/**, tests/Feature/InfluencersFindTest.php, tests/Feature/GenerateInfluencerBriefJobTest.php, tests/Feature/Mcp/ListInfluencersToolTest.php, tests/Unit/Services/Influencers/** | .ai/rules/influencers.md |
| config/snitch.php | .ai/rules/config.md |
| app/Mail/**, app/Http/Controllers/Marketing/ContactController.php, app/Http/Requests/Marketing/**, resources/js/pages/marketing/Contact.vue, config/mail.php, config/services.php | .ai/rules/mail.md |
| deploy/**, compose.prod.yaml, .github/workflows/prod_deploy.yml, scripts/deploy-production.sh | .ai/rules/production-url.md |
| Dockerfile, docker/production/**, app/Console/Commands/WarmWorkOsJwkCommand.php, app/Support/WorkOs/**, app/Providers/AppServiceProvider.php, compose.prod.yaml | .ai/rules/production-web.md |
| app/Jobs/** | .ai/rules/jobs.md |
| app/Services/Winners/**, app/Jobs/ScoreWinnersJob.php, app/Http/Controllers/WinnerController.php | .ai/rules/winners.md |
| app/Models/Post.php, app/Models/SocialAccount.php, app/Models/TrackedAccount.php, app/Services/SocialAccounts/** | .ai/rules/models.md |
| resources/css/app.css, resources/js/**/*.{vue,ts,css}, resources/views/app.blade.php | .ai/rules/frontend-dark-mode.md |
| resources/js/app.ts, vite.config.ts | .ai/rules/inertia-ssr.md |
| config/seo.php, app/Support/Seo.php, resources/views/app.blade.php, resources/js/components/marketing/SeoHead.vue, resources/js/components/AppHead.vue, resources/js/layouts/PublicLayout.vue, public/robots.txt, routes/web.php, bootstrap/app.php | .ai/rules/seo.md |
| app/Models/Blog.php, app/Enums/BlogStatus.php, app/Http/Controllers/BlogController.php, app/Services/Blog/**, app/Console/Commands/GenerateBlogPostCommand.php, app/Console/Commands/PublishBlogDraftsCommand.php, config/blog.php, resources/js/pages/blog/**, routes/web.php, routes/console.php | .ai/rules/blog.md |
| resources/js/components/PlatformEmbed.vue, resources/js/components/FeedContactCell.vue, resources/js/lib/embedLoadQueue.ts, app/Support/PlatformEmbed.php | .ai/rules/platform-embeds.md |
| tests/** | .ai/rules/tests.md |
