<?php

use App\Http\Controllers\AgentsController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\BacklogController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\BrandProfileController;
use App\Http\Controllers\CompetitorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExploreController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\InfluencerController;
use App\Http\Controllers\Marketing\ContactController;
use App\Http\Controllers\Marketing\PricingController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\Settings\WinnerRuleController;
use App\Http\Controllers\WinnerController;
use App\Http\Middleware\EnsureBrandProfile;
use App\Http\Middleware\EnsureProductAccess;
use App\Support\Seo;
use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;

Route::inertia('/', 'Welcome')->name('home');
Route::inertia('/about', 'marketing/About')->name('about');
Route::inertia('/how-it-works', 'marketing/HowItWorks')->name('how-it-works');
Route::get('/pricing', PricingController::class)->name('pricing');
Route::get('/agents', [AgentsController::class, 'show'])->name('agents');
Route::permanentRedirect('/for-agents', '/agents')->name('mcp');
Route::inertia('/privacy', 'marketing/Privacy')->name('privacy');
Route::inertia('/terms', 'marketing/Terms')->name('terms');
Route::inertia('/cookies', 'marketing/Cookies')->name('cookies');

Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');
Route::get('/analytics.json', [AnalyticsController::class, 'json'])->name('analytics.json');

Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/{blog:slug}', [BlogController::class, 'show'])->name('blog.show');

Route::get('/contact', [ContactController::class, 'create'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:contact')
    ->name('contact.store');

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

    Route::post('/agents/token', [AgentsController::class, 'rotateToken'])
        ->middleware('throttle:10,1')
        ->name('agents.token');

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

        // Legacy Competitors URLs (bookmarks / old clients).
        Route::permanentRedirect('/competitors', '/snitches');
        Route::get('/competitors/{path}', function (string $path) {
            return redirect('/snitches/'.$path, 301);
        })->where('path', '.*')->name('competitors.legacy-redirect');

        Route::get('/influencers', [InfluencerController::class, 'index'])->name('influencers.index');
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

        Route::get('/feed', [FeedController::class, 'index'])->name('feed.index');
        Route::get('/feed/{post}', [FeedController::class, 'show'])->name('feed.show');

        Route::get('/backlog', [BacklogController::class, 'index'])->name('backlog.index');

        Route::get('/explore', [ExploreController::class, 'index'])->name('explore.index');

        Route::get('/winners', [WinnerController::class, 'index'])->name('winners.index');
        Route::post('/winners/rescore', [WinnerController::class, 'rescore'])->name('winners.rescore');
        Route::get('/winners/rescore/{runId}', [WinnerController::class, 'rescoreStatus'])->name('winners.rescore.status');

        Route::get('/brand', [BrandProfileController::class, 'edit'])->name('brand.edit');
        Route::put('/brand', [BrandProfileController::class, 'update'])->name('brand.update');

        Route::put('/winners/rules', [WinnerRuleController::class, 'update'])->name('winners.rules.update');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
