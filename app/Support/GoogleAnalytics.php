<?php

namespace App\Support;

final class GoogleAnalytics
{
    public static function measurementId(): ?string
    {
        $id = config('services.google.analytics_id');

        if (! is_string($id) || $id === '') {
            return null;
        }

        return $id;
    }

    public static function enabled(): bool
    {
        if (self::measurementId() === null) {
            return false;
        }

        $flag = config('services.google.analytics_enabled');

        if ($flag === null || $flag === '') {
            return app()->isProduction();
        }

        return filter_var($flag, FILTER_VALIDATE_BOOL);
    }

    public static function adsId(): ?string
    {
        $id = config('services.google.ads_id');

        if (! is_string($id) || $id === '') {
            return null;
        }

        return $id;
    }

    public static function adsSignupSendTo(): ?string
    {
        $sendTo = config('services.google.ads_signup_send_to');

        if (! is_string($sendTo) || $sendTo === '') {
            return null;
        }

        return $sendTo;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public static function queueEvent(string $name, array $params = []): void
    {
        $events = session()->get('ga_events', []);

        if (! is_array($events)) {
            $events = [];
        }

        $events[] = [
            'name' => $name,
            'params' => $params,
        ];

        session()->put('ga_events', $events);
    }

    /**
     * @return list<array{name: string, params: array<string, mixed>}>
     */
    public static function takeEvents(): array
    {
        return once(function (): array {
            $events = session()->pull('ga_events', []);

            if (! is_array($events)) {
                return [];
            }

            return array_values($events);
        });
    }

    /**
     * @param  array<string, mixed>  $session
     */
    public static function queuePurchase(array $session): void
    {
        $sessionId = is_string($session['id'] ?? null) ? $session['id'] : null;

        if ($sessionId === null || $sessionId === '') {
            return;
        }

        if (! cache()->add('ga_purchase:'.$sessionId, true, now()->addDay())) {
            return;
        }

        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $isCredits = ($metadata['snitch_product'] ?? null) === 'credits';
        $amountTotal = (int) ($session['amount_total'] ?? 0);
        $currency = strtoupper((string) ($session['currency'] ?? 'gbp'));

        self::queueEvent('purchase', [
            'transaction_id' => $sessionId,
            'value' => round($amountTotal / 100, 2),
            'currency' => $currency !== '' ? $currency : 'GBP',
            'items' => [[
                'item_id' => $isCredits ? 'credits' : 'platform',
                'item_name' => $isCredits ? 'Credit pack' : 'Platform plan',
                'quantity' => 1,
            ]],
        ]);
    }
}
