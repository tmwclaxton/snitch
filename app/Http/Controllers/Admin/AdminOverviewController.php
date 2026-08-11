<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminOverviewService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminOverviewController extends Controller
{
    public function __construct(private AdminOverviewService $overview) {}

    public function index(Request $request): Response
    {
        $grain = (string) $request->string('grain', 'day');
        $periods = $request->integer('periods') ?: null;

        return Inertia::render('admin/Overview', $this->overview->overview($grain, $periods));
    }
}
