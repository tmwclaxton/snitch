<?php

use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;

Route::middleware([
    'auth',
    ValidateSessionWithWorkOS::class,
])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::get('billing', [BillingController::class, 'index'])->name('billing.edit');
    Route::get('billing/charges', [BillingController::class, 'charges'])->name('billing.charges');
    Route::post('billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::post('billing/portal', [BillingController::class, 'portal'])->name('billing.portal');

    Route::redirect('settings/billing', '/billing');
});
