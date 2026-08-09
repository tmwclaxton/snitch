<?php

namespace App\Http\Controllers;

use App\Services\Billing\AccountClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClaimController extends Controller
{
    public function show(string $token, AccountClaimService $claims): Response|RedirectResponse
    {
        $account = $claims->findUnclaimedByToken($token);

        if ($account === null) {
            return redirect()->route('login')
                ->with('toast', [
                    'type' => 'error',
                    'message' => __('This claim link is invalid or already used.'),
                ]);
        }

        session(['snitch_claim_token' => $token]);

        return Inertia::render('claim/Show', [
            'email' => $account->email,
            'name' => $account->name,
            'claimToken' => $token,
            'loginUrl' => route('login'),
        ]);
    }

    public function start(Request $request, string $token, AccountClaimService $claims): RedirectResponse
    {
        if ($claims->findUnclaimedByToken($token) === null) {
            return redirect()->route('login');
        }

        session(['snitch_claim_token' => $token]);

        return redirect()->route('login');
    }
}
