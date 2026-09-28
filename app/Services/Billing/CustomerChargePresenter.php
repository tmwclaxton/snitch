<?php

namespace App\Services\Billing;

use App\Enums\BillingVendor;
use App\Models\CreditLedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Customer-facing credit lines: no vendor names, grouped by action, rounded credits.
 */
class CustomerChargePresenter
{
    public function __construct(private LedgerChargePresenter $ledger) {}

    /**
     * @param  Collection<int, CreditLedgerEntry>  $entries
     * @return list<array{
     *     key: string,
     *     description: string,
     *     credits: int,
     *     count: int,
     *     link: array{type: string, id?: int, label: string}|null,
     *     created_at: string|null,
     *     date: string|null
     * }>
     */
    public function groupEntries(Collection $entries): array
    {
        /** @var array<string, array{action: string, meta: array<string, mixed>, amount: float, count: int, latest: ?Carbon, link: ?array}> $groups */
        $groups = [];

        foreach ($entries as $entry) {
            if ((float) $entry->amount_pence === 0.0) {
                continue;
            }

            // Credits / bonuses stay as single lines; spend actions group.
            if (in_array(
                $entry->vendor instanceof BillingVendor ? $entry->vendor->value : (string) $entry->vendor,
                [BillingVendor::Bonus->value, BillingVendor::Topup->value],
                true,
            ) || (float) $entry->amount_pence > 0) {
                $key = 'single:'.$entry->id;
            } else {
                $meta = is_array($entry->meta) ? $entry->meta : [];
                $handle = $this->handleKey($meta);
                $day = $entry->created_at?->timezone(config('app.timezone'))->toDateString() ?? 'unknown';
                $key = $day.'|'.$entry->action.'|'.$handle;
            }

            if (! isset($groups[$key])) {
                $meta = is_array($entry->meta) ? $entry->meta : [];
                $presented = $this->ledger->present($entry->action, $meta);
                $groups[$key] = [
                    'action' => $entry->action,
                    'meta' => $meta,
                    'amount' => 0.0,
                    'count' => 0,
                    'latest' => $entry->created_at,
                    'link' => $presented['link'],
                ];
            }

            $groups[$key]['amount'] += abs((float) $entry->amount_pence);
            $groups[$key]['count']++;

            if (
                $entry->created_at !== null
                && ($groups[$key]['latest'] === null || $entry->created_at->gt($groups[$key]['latest']))
            ) {
                $groups[$key]['latest'] = $entry->created_at;
                $presented = $this->ledger->present(
                    $entry->action,
                    is_array($entry->meta) ? $entry->meta : [],
                );
                $groups[$key]['link'] = $presented['link'];
                $groups[$key]['meta'] = is_array($entry->meta) ? $entry->meta : [];
            }
        }

        $lines = [];

        foreach ($groups as $key => $group) {
            $credits = $this->creditsFromPence($group['amount']);
            $lines[] = [
                'key' => $key,
                'description' => $this->lineDescription(
                    $group['action'],
                    $group['meta'],
                    $group['count'],
                    $credits,
                    (float) $group['amount'] > 0 && str_starts_with($key, 'single:'),
                ),
                'credits' => $credits,
                'count' => $group['count'],
                'link' => $group['link'],
                'created_at' => $group['latest']?->toIso8601String(),
                'date' => $group['latest']?->timezone(config('app.timezone'))->toDateString(),
            ];
        }

        usort($lines, function (array $a, array $b): int {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function lineDescription(
        string $action,
        array $meta,
        int $count,
        int $credits,
        bool $isCredit = false,
    ): string {
        if ($isCredit || (float) ($meta['_force_credit'] ?? 0) > 0) {
            $base = $this->ledger->description($action, $meta);

            return $base.', '.$this->creditsLabel($credits);
        }

        $handle = $this->stringMeta($meta['handle'] ?? null);
        $handlePart = $handle !== null ? ' @'.ltrim($handle, '@') : '';

        $base = match ($action) {
            'sync.account' => 'Weekly sync'.$handlePart,
            'analyze.post' => 'Post analysis',
            'embed.analysis' => 'Indexed analysis',
            'competitors.suggest' => 'Suggested snitches',
            'influencers.find' => 'Suggested influencers',
            'influencer.brief' => 'Influencer brief',
            'competitor.brief' => 'Snitch brief',
            'brand.autofill' => 'Brand autofill',
            'winners.copy' => 'Winner copy',
            'explore.search' => 'Explore search',
            'explore.view' => 'Explore view',
            'brief.weekly' => 'Weekly brief',
            'claim_bonus' => 'Welcome credits',
            'subscription_bonus' => 'Plan credits',
            'credits.topup' => 'Credit top-up',
            default => $this->ledger->description($action, $meta),
        };

        if ($count > 1 && ! in_array($action, ['claim_bonus', 'subscription_bonus', 'credits.topup'], true)) {
            $base .= ' x'.$count;
        } elseif ($handle !== null && ! str_contains($base, '@')) {
            $base .= $handlePart;
        }

        return $base.', '.$this->creditsLabel($credits);
    }

    public function creditsFromPence(float $pence): int
    {
        $abs = abs($pence);

        if ($abs <= 0) {
            return 0;
        }

        // 1 credit = 1p of usage balance; round half-up, never show 0 for a real charge.
        return max(1, (int) round($abs));
    }

    public function creditsLabel(int $credits): string
    {
        return $credits === 1 ? '1 credit' : $credits.' credits';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function handleKey(array $meta): string
    {
        $handle = $this->stringMeta($meta['handle'] ?? null);

        return $handle !== null ? strtolower(ltrim($handle, '@')) : '';
    }

    private function stringMeta(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
