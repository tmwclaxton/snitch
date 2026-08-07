<?php

use App\Http\Controllers\CompetitorController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\Marketing\ContactController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\Settings\WinnerRuleController;
use App\Http\Controllers\WinnerController;
use App\Http\Middleware\EnsureBrandProfile;
use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;

Route::inertia('/', 'Welcome')->name('home');
Route::inertia('/about', 'marketing/About')->name('about');
Route::inertia('/how-it-works', 'marketing/HowItWorks')->name('how-it-works');
Route::inertia('/privacy', 'marketing/Privacy')->name('privacy');
Route::inertia('/terms', 'marketing/Terms')->name('terms');
Route::inertia('/cookies', 'marketing/Cookies')->name('cookies');

Route::get('/contact', [ContactController::class, 'create'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('contact.store');

Route::get('/sitemap.xml', function () {
    $urls = [
        route('home', absolute: true),
        route('about', absolute: true),
        route('how-it-works', absolute: true),
        route('contact', absolute: true),
        route('privacy', absolute: true),
        route('terms', absolute: true),
        route('cookies', absolute: true),
    ];

    $body = collect($urls)->map(function (string $loc): string {
        return '  <url><loc>'.e($loc).'</loc></url>';
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
    Route::post('/onboarding/suggest', [OnboardingController::class, 'suggest'])->name('onboarding.suggest');
    Route::post('/onboarding/confirm', [OnboardingController::class, 'confirm'])->name('onboarding.confirm');

    Route::middleware([EnsureBrandProfile::class])->group(function () {
        Route::inertia('dashboard', 'Dashboard')->name('dashboard');

        Route::get('/competitors', [CompetitorController::class, 'index'])->name('competitors.index');
        Route::post('/competitors', [CompetitorController::class, 'store'])->name('competitors.store');
        Route::delete('/competitors/{trackedAccount}', [CompetitorController::class, 'destroy'])->name('competitors.destroy');
        Route::post('/competitors/{trackedAccount}/sync', [CompetitorController::class, 'sync'])->name('competitors.sync');

        Route::get('/feed', [FeedController::class, 'index'])->name('feed.index');
        Route::get('/feed/{post}', [FeedController::class, 'show'])->name('feed.show');

        Route::get('/winners', [WinnerController::class, 'index'])->name('winners.index');
        Route::post('/winners/rescore', [WinnerController::class, 'rescore'])->name('winners.rescore');

        Route::get('/settings/winners', [WinnerRuleController::class, 'edit'])->name('winners.settings.edit');
        Route::put('/settings/winners', [WinnerRuleController::class, 'update'])->name('winners.settings.update');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
