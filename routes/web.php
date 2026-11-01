<?php

use App\Http\Controllers\Admin\AdminActivityController;
use App\Http\Controllers\Admin\AdminOverviewController;
use App\Http\Controllers\Admin\AdminReferralController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AutomationRunController;
use App\Http\Controllers\Admin\CuratorTokenController;
use App\Http\Controllers\AgentsController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\BacklogController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\BrandProfileController;
use App\Http\Controllers\CompetitorController;
use App\Http\Controllers\ConnectController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExploreController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\GrowthController;
use App\Http\Controllers\InfluencerController;
use App\Http\Controllers\Marketing\ContactController;
use App\Http\Controllers\Marketing\PricingController;
use App\Http\Controllers\MonthlyReportController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\Ops\SignedLoginController;
use App\Http\Controllers\PublicReportController;
use App\Http\Controllers\Settings\WinnerRuleController;
use App\Http\Controllers\WinnerController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureBrandProfile;
use App\Http\Middleware\EnsureProductAccess;
use App\Http\Middleware\ValidateSessionWithWorkOS;
use App\Models\User;
use App\Support\Seo;
use Illuminate\Support\Facades\Route;

if (app()->environment('local') && blank(config('services.workos.client_id'))) {
    Route::get('/__local-login/{user}', function (User $user) {
        auth()->login($user);
        request()->session()->put('workos_access_token', 'local-dev-access');
        request()->session()->put('workos_refresh_token', 'local-dev-refresh');

        return redirect()->route('dashboard');
    })->name('local.login');
}

Route::inertia('/', 'Welcome')->name('home');
Route::inertia('/about', 'marketing/About')->name('about');
Route::inertia('/how-it-works', 'marketing/HowItWorks')->name('how-it-works');
Route::get('/pricing', PricingController::class)->name('pricing');
Route::get('/agents', [AgentsController::class, 'show'])->name('agents');
Route::permanentRedirect('/for-agents', '/agents')->name('mcp');
Route::inertia('/privacy', 'marketing/Privacy')->name('privacy');
Route::inertia('/terms', 'marketing/Terms')->name('terms');
Route::inertia('/cookies', 'marketing/Cookies')->name('cookies');
// Special Calendly landing page (shared PublicLayout chrome; stays out of the sitemap).
Route::inertia('/beta', 'marketing/Beta')->name('beta');
Route::view('/pitch', 'pitch')->name('pitch');
Route::get('/ads', fn () => redirect('/dashboard', 301))->name('ads.legacy');

Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');
Route::get('/analytics.json', [AnalyticsController::class, 'json'])->name('analytics.json');

Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/{blog:slug}', [BlogController::class, 'show'])->name('blog.show');

Route::get('/r/{token}', [PublicReportController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->name('reports.public');

Route::get('/contact', [ContactController::class, 'create'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:contact')
    ->name('contact.store');

Route::get('/ops/login/{user}', SignedLoginController::class)
    ->middleware('signed')
    ->name('ops.signed-login');

Route::get('/sitemap.xml', function () {
    $body = collect(Seo::sitemapEntries())->map(function (array $entry): string {
        return '  <url>'
            .'<loc>'.e($entry['loc']).'</loc>'
            .'<lastmod>'.e($entry['lastmod']).'</lastmod>'
            .'<changefreq>'.e($entry['changefreq']).'</changefreq>'
            .'<priority>'.e($entry['priority']).'</priority>'
            .'</url>';
    })->implode("\n");

    return response(
        <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
{$body}
</urlset>
XML,
        200,
        ['Content-Type' => 'application/xml'],
    );
})->name('sitemap');

Route::middleware(['auth', ValidateSessionWithWorkOS::class])->group(function () {
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
    Route::post('/onboarding/autofill', [OnboardingController::class, 'startAutofill'])
        ->middleware('throttle:10,1')
        ->name('onboarding.autofill');
    Route::get('/onboarding/autofill/{autofillId}', [OnboardingController::class, 'autofillStatus'])
        ->name('onboarding.autofill.status');

    Route::middleware(EnsureAdmin::class)->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [AdminOverviewController::class, 'index'])->name('overview');
        Route::get('/activity', [AdminActivityController::class, 'index'])->name('activity');
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::get('/curator-tokens', [CuratorTokenController::class, 'index'])->name('curator-tokens.index');
        Route::delete('/curator-tokens/{token}', [CuratorTokenController::class, 'destroy'])
            ->middleware('throttle:20,1')
            ->name('curator-tokens.destroy');
        Route::get('/automation-runs', [AutomationRunController::class, 'index'])->name('automation-runs.index');
        Route::post('/automation-runs/{run}/revoke', [AutomationRunController::class, 'revoke'])
            ->middleware('throttle:20,1')
            ->name('automation-runs.revoke');
        Route::get('/referrals', [AdminReferralController::class, 'index'])->name('referrals.index');
        Route::post('/referrals', [AdminReferralController::class, 'store'])->name('referrals.store');
        Route::get('/referrals/{referral}', [AdminReferralController::class, 'show'])->name('referrals.show');
        Route::patch('/referrals/{referral}', [AdminReferralController::class, 'update'])->name('referrals.update');
    });

    Route::get('/connect', [ConnectController::class, 'show'])->name('connect.show');
    Route::post('/connect/token', [ConnectController::class, 'rotateToken'])
        ->middleware('throttle:10,1')
        ->name('connect.token');
    Route::delete('/connect/token', [ConnectController::class, 'revokeToken'])
        ->middleware('throttle:10,1')
        ->name('connect.token.revoke');

    Route::middleware([EnsureBrandProfile::class, EnsureProductAccess::class])->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('/snitches', [CompetitorController::class, 'index'])->name('competitors.index');
        Route::post('/snitches', [CompetitorController::class, 'store'])->name('competitors.store');
        Route::post('/snitches/suggest', [CompetitorController::class, 'suggest'])
            ->middleware('throttle:10,1')
            ->name('competitors.suggest');
        Route::get('/snitches/suggest/{suggestId}', [CompetitorController::class, 'suggestStatus'])
            ->name('competitors.suggest.status');
        Route::post('/snitches/brief', [CompetitorController::class, 'generateBrief'])
            ->middleware('throttle:20,1')
            ->name('competitors.brief');
        Route::patch('/snitches/brief', [CompetitorController::class, 'updateBrief'])
            ->name('competitors.brief.update');
        Route::post('/snitches/confirm-suggestions', [CompetitorController::class, 'confirmSuggestions'])->name('competitors.confirm-suggestions');
        Route::post('/snitches/dismiss-suggestions', [CompetitorController::class, 'dismissSuggestions'])->name('competitors.dismiss-suggestions');
        Route::post('/snitches/batch-sync', [CompetitorController::class, 'batchSync'])->name('competitors.batch-sync');
        Route::post('/snitches/batch-destroy', [CompetitorController::class, 'batchDestroy'])->name('competitors.batch-destroy');
        Route::get('/snitches/{trackedAccount}', [CompetitorController::class, 'show'])->name('competitors.show');
        Route::delete('/snitches/{trackedAccount}', [CompetitorController::class, 'destroy'])->name('competitors.destroy');
        Route::post('/snitches/{trackedAccount}/sync', [CompetitorController::class, 'sync'])->name('competitors.sync');
        Route::post('/snitches/{trackedAccount}/own', [CompetitorController::class, 'markOwn'])->name('competitors.own');
        Route::delete('/snitches/{trackedAccount}/own', [CompetitorController::class, 'unmarkOwn'])->name('competitors.unown');

        Route::permanentRedirect('/tracking', '/snitches');
        Route::get('/tracking/{path}', function (string $path) {
            return redirect('/snitches/'.$path, 301);
        })->where('path', '.*');

        Route::permanentRedirect('/competitors', '/snitches');
        Route::get('/competitors/{path}', function (string $path) {
            return redirect('/snitches/'.$path, 301);
        })->where('path', '.*')->name('competitors.legacy-redirect');

        Route::get('/ad-library', fn () => redirect('/dashboard', 301))->name('ads.index');
        Route::get('/influencers', [InfluencerController::class, 'index'])->name('influencers.index');
        Route::get('/feed', [FeedController::class, 'index'])->name('feed.index');
        Route::get('/feed/{post}', [FeedController::class, 'show'])->name('feed.show');
        Route::get('/backlog', [BacklogController::class, 'index'])->name('backlog.index');
        Route::get('/explore', [ExploreController::class, 'index'])->name('explore.index');
        Route::permanentRedirect('/today', '/dashboard');
        Route::permanentRedirect('/brief', '/dashboard');
        Route::permanentRedirect('/executive-brief', '/dashboard');
        Route::permanentRedirect('/chat', '/dashboard');
        Route::get('/growth', [GrowthController::class, 'index'])->name('growth.index');
        Route::get('/growth/report', [MonthlyReportController::class, 'show'])->name('growth.report');
        Route::get('/growth/report/{report}/pdf', [MonthlyReportController::class, 'pdf'])->name('growth.report.pdf');
        Route::get('/winners', [WinnerController::class, 'index'])->name('winners.index');
        Route::get('/brand', [BrandProfileController::class, 'edit'])->name('brand.edit');

        Route::post('/influencers/brief', [InfluencerController::class, 'generateBrief'])
            ->middleware('throttle:10,1')
            ->name('influencers.brief');
        Route::patch('/influencers/brief', [InfluencerController::class, 'updateBrief'])
            ->name('influencers.brief.update');
        Route::post('/influencers/search', [InfluencerController::class, 'search'])
            ->middleware('throttle:10,1')
            ->name('influencers.search');
        Route::get('/influencers/search/{runId}', [InfluencerController::class, 'searchStatus'])
            ->name('influencers.search.status');
        Route::post('/influencers/keep', [InfluencerController::class, 'keep'])->name('influencers.keep');
        Route::post('/influencers/discard', [InfluencerController::class, 'discard'])->name('influencers.discard');
        Route::post('/influencers/keep-many', [InfluencerController::class, 'keepMany'])->name('influencers.keep-many');
        Route::post('/influencers/discard-many', [InfluencerController::class, 'discardMany'])->name('influencers.discard-many');
        Route::post('/influencers/batch-destroy', [InfluencerController::class, 'batchDestroy'])->name('influencers.batch-destroy');

        Route::post('/growth/report/{report}/share', [MonthlyReportController::class, 'share'])
            ->name('growth.report.share');
        Route::post('/growth/report/{report}/revoke', [MonthlyReportController::class, 'revoke'])
            ->name('growth.report.revoke');

        Route::post('/winners/rescore', [WinnerController::class, 'rescore'])->name('winners.rescore');
        Route::get('/winners/rescore/{runId}', [WinnerController::class, 'rescoreStatus'])->name('winners.rescore.status');

        Route::put('/brand', [BrandProfileController::class, 'update'])->name('brand.update');

        Route::put('/winners/rules', [WinnerRuleController::class, 'update'])->name('winners.rules.update');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
