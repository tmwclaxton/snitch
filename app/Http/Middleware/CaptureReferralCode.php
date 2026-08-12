<?php

namespace App\Http\Middleware;

use App\Services\Referrals\ReferralAttribution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CaptureReferralCode
{
    public function __construct(private ReferralAttribution $attribution) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query->has('ref')) {
            $this->attribution->captureFromQuery($request);
        }

        return $next($request);
    }
}
