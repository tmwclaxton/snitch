<?php

namespace App\Support;

use Illuminate\Http\Request;

class ClientIp
{
    /**
     * Prefer Cloudflare's overwritten client IP when present.
     */
    public static function from(Request $request): string
    {
        $cloudflareIp = $request->headers->get('CF-Connecting-IP');

        if (is_string($cloudflareIp) && filter_var($cloudflareIp, FILTER_VALIDATE_IP)) {
            return $cloudflareIp;
        }

        return (string) $request->ip();
    }
}
