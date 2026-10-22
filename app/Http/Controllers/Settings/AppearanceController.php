<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class AppearanceController extends Controller
{
    /**
     * Show the appearance settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/Appearance');
    }
}
