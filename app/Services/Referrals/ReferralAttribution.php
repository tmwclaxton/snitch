<?php

namespace App\Services\Referrals;

use App\Models\ReferralCode;
use App\Models\ReferralVisit;
use App\Models\User;
use App\Support\ClientIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class ReferralAttribution
{
    public const COOKIE_NAME = 'snitch_ref';

    public const SESSION_KEY = 'snitch_ref';

    public const COOKIE_DAYS = 90;

    public function normalizeCode(?string $code): ?string
    {
        if (! is_string($code)) {
            return null;
        }

        $normalized = Str::lower(trim($code));

        if ($normalized === '' || ! preg_match('/^[a-z0-9][a-z0-9_-]{1,62}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    public function findActiveByCode(?string $code): ?ReferralCode
    {
        $normalized = $this->normalizeCode($code);

        if ($normalized === null) {
            return null;
        }

        return ReferralCode::query()
            ->where('code', $normalized)
            ->where('is_active', true)
            ->first();
    }

    public function codeFromRequest(Request $request): ?ReferralCode
    {
        $fromCookie = $request->cookie(self::COOKIE_NAME);
        $fromSession = $this->sessionValue($request, self::SESSION_KEY);

        foreach ([$fromCookie, $fromSession] as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $code = $this->findActiveByCode($candidate);

            if ($code !== null) {
                return $code;
            }
        }

        return null;
    }

    public function captureFromQuery(Request $request): void
    {
        $queryCode = $this->normalizeCode($request->query('ref'));

        if ($queryCode === null) {
            return;
        }

        $referral = $this->findActiveByCode($queryCode);

        if ($referral === null) {
            return;
        }

        $existingCookie = $this->normalizeCode(
            is_string($request->cookie(self::COOKIE_NAME)) ? $request->cookie(self::COOKIE_NAME) : null,
        );
        $existingSession = $this->normalizeCode(
            is_string($this->sessionValue($request, self::SESSION_KEY))
                ? (string) $this->sessionValue($request, self::SESSION_KEY)
                : null,
        );

        if ($existingCookie === null && $existingSession === null) {
            $this->putSessionValue($request, self::SESSION_KEY, $referral->code);
            Cookie::queue($this->makeCookie($referral->code));
        } elseif ($existingSession === null && $existingCookie !== null) {
            $this->putSessionValue($request, self::SESSION_KEY, $existingCookie);
        }

        $this->recordVisit($request, $referral);
    }

    public function bindToUser(User $user, ?Request $request = null): bool
    {
        if ($user->referral_code_id !== null) {
            return false;
        }

        $request ??= request();

        if (! $request instanceof Request) {
            return false;
        }

        $code = $this->codeFromRequest($request);

        if ($code === null) {
            return false;
        }

        $user->forceFill(['referral_code_id' => $code->id])->save();

        return true;
    }

    public function makeCookie(string $code): SymfonyCookie
    {
        return cookie(
            name: self::COOKIE_NAME,
            value: $code,
            minutes: self::COOKIE_DAYS * 24 * 60,
            path: '/',
            domain: null,
            secure: (bool) config('session.secure', false),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    private function sessionValue(Request $request, string $key): mixed
    {
        if (! $request->hasSession()) {
            return null;
        }

        return $request->session()->get($key);
    }

    private function putSessionValue(Request $request, string $key, string $value): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->put($key, $value);
    }

    public function recordVisit(Request $request, ReferralCode $referral): void
    {
        $ip = ClientIp::from($request);
        $ipHash = hash('sha256', $ip.'|'.(string) config('app.key'));
        $visitDate = now()->toDateString();
        $userAgent = Str::limit((string) $request->userAgent(), 255, '');

        ReferralVisit::query()->firstOrCreate(
            [
                'referral_code_id' => $referral->id,
                'ip_hash' => $ipHash,
                'visit_date' => $visitDate,
            ],
            [
                'user_agent' => $userAgent !== '' ? $userAgent : null,
                'created_at' => now(),
            ],
        );
    }
}
