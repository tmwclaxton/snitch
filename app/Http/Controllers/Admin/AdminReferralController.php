<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreReferralCodeRequest;
use App\Http\Requests\Admin\UpdateReferralCodeRequest;
use App\Models\ReferralCode;
use App\Services\Admin\AdminReferralService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminReferralController extends Controller
{
    public function __construct(private AdminReferralService $referrals) {}

    public function index(): Response
    {
        return Inertia::render('admin/Referrals', $this->referrals->index());
    }

    public function store(StoreReferralCodeRequest $request): RedirectResponse
    {
        $this->referrals->create($request->validated(), $request->user());

        return redirect()->route('admin.referrals.index');
    }

    public function show(Request $request, ReferralCode $referral): Response
    {
        $grain = (string) $request->string('grain', 'day');
        $periods = $request->integer('periods') ?: null;
        $search = $request->string('search')->toString();
        $sort = $request->string('sort')->toString();
        $direction = $request->string('direction')->toString();
        $expandedUserId = $request->integer('expand') ?: null;

        return Inertia::render('admin/ReferralShow', $this->referrals->show(
            referral: $referral,
            grain: $grain,
            periods: $periods,
            search: $search !== '' ? $search : null,
            sort: $sort !== '' ? $sort : null,
            direction: $direction !== '' ? $direction : null,
            page: max(1, $request->integer('page', 1)),
            expandedUserId: $expandedUserId > 0 ? $expandedUserId : null,
        ));
    }

    public function update(UpdateReferralCodeRequest $request, ReferralCode $referral): RedirectResponse
    {
        $this->referrals->update($referral, $request->validated());

        return redirect()->back();
    }
}
