<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Temporary signed login for ops screenshots. Do not link from the product UI.
 */
class SignedLoginController extends Controller
{
    public function __invoke(Request $request, User $user): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            abort(403);
        }

        auth()->login($user);
        $request->session()->regenerate();
        $request->session()->put('workos_access_token', 'ops-signed-login');
        $request->session()->put('workos_refresh_token', 'ops-signed-login');
        $request->session()->put('ops_signed_login_until', now()->addMinutes(30)->getTimestamp());

        return redirect()->intended(route('dashboard'));
    }
}
