<?php

use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\McpController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Middleware\ValidateSessionWithWorkOS;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    ValidateSessionWithWorkOS::class,
])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Appearance is hidden while the logged-in app is pinned to Caution Tape
    // night. Keep settings/Appearance.vue so the setting can come back.
    Route::redirect('settings/appearance', '/settings/profile')->name('appearance.edit');

    Route::get('settings/mcp', [McpController::class, 'show'])->name('settings.mcp.show');
    Route::post('settings/mcp/token', [McpController::class, 'rotateToken'])
        ->middleware('throttle:10,1')
        ->name('settings.mcp.token');

    Route::get('billing', [BillingController::class, 'index'])->name('billing.edit');
    Route::get('billing/charges', [BillingController::class, 'charges'])->name('billing.charges');
    Route::post('billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::post('billing/portal', [BillingController::class, 'portal'])->name('billing.portal');

    Route::redirect('settings/billing', '/billing');
});
