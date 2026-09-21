<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tracking\CheckWatchingYouRequest;
use App\Services\Tracking\WatchingYouService;
use Illuminate\Http\RedirectResponse;

class WatchingYouController extends Controller
{
    public function __invoke(CheckWatchingYouRequest $request, WatchingYouService $watching): RedirectResponse
    {
        $check = $watching->lookup(
            $request->validated('handle'),
            $request->user()->id,
        );

        return redirect()
            ->route('dashboard')
            ->with('watching_check', $check);
    }
}
