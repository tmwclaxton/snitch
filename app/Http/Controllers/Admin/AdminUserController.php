<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUserIndexRequest;
use App\Models\User;
use App\Services\Admin\AdminUserService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminUserController extends Controller
{
    public function __construct(private AdminUserService $users) {}

    public function index(AdminUserIndexRequest $request): Response
    {
        $validated = $request->validated();

        return Inertia::render('admin/users/Index', $this->users->index(
            search: filled($validated['search'] ?? null) ? (string) $validated['search'] : null,
            sort: filled($validated['sort'] ?? null) ? (string) $validated['sort'] : null,
            direction: filled($validated['direction'] ?? null) ? (string) $validated['direction'] : null,
            plan: filled($validated['plan'] ?? null) ? (string) $validated['plan'] : null,
            page: max(1, (int) ($validated['page'] ?? 1)),
        ));
    }

    public function show(Request $request, User $user): Response
    {
        $grain = (string) $request->string('grain', 'day');
        $periods = $request->integer('periods') ?: null;

        return Inertia::render('admin/users/Show', $this->users->show($user, $grain, $periods));
    }
}
