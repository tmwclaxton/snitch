<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminActivityService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminActivityController extends Controller
{
    public function __construct(private AdminActivityService $activity) {}

    public function index(Request $request): Response
    {
        $grain = (string) $request->string('grain', 'day');
        $periods = $request->integer('periods') ?: null;

        return Inertia::render('admin/Activity', $this->activity->activity($grain, $periods));
    }
}
