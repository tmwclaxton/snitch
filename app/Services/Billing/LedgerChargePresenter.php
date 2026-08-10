<?php

namespace App\Services\Billing;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Enums\TrackedAccountKind;

class LedgerChargePresenter
{
    /**
     * Human-readable charge line and optional in-app link target.
     *
     * @param  array<string, mixed>|null  $meta
     * @return array{
     *     description: string,
     *     link: array{type: string, id?: int, label: string}|null
     * }
     */
    public function present(string $action, ?array $meta): array
    {
        $meta ??= [];

        return [
            'description' => $this->description($action, $meta),
            'link' => $this->link($action, $meta),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function description(string $action, array $meta): string
    {
        $override = $meta['description'] ?? null;
        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        return match ($action) {
            'analyze.post' => $this->analyzeDescription($meta),
            'embed.analysis' => 'Indexed post analysis',
            'sync.account' => $this->syncDescription($meta),
            'competitors.suggest' => 'Suggested competitors',
            'influencers.find' => 'Suggested influencers',
            'influencer.brief' => 'Generated influencer brief',
            'brand.autofill' => 'Brand autofill',
            'winners.copy' => 'Generated winner copy',
            'apify.run' => 'Apify run',
            'tikhub.run' => 'TikHub run',
            'claim_bonus' => 'Welcome credits',
            'subscription_bonus' => 'Plan credits',
            'credits.topup' => 'Credit top-up',
            default => $this->humanizeAction($action),
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{type: string, id?: int, label: string}|null
     */
    public function link(string $action, array $meta): ?array
    {
        $postId = $this->positiveInt($meta['post_id'] ?? null);
        if ($postId !== null) {
            return [
                'type' => 'post',
                'id' => $postId,
                'label' => 'View post',
            ];
        }

        $trackedAccountId = $this->positiveInt($meta['tracked_account_id'] ?? null);
        if ($trackedAccountId !== null) {
            $handle = $this->stringMeta($meta['handle'] ?? null);
            $label = $handle !== null ? '@'.ltrim($handle, '@') : 'View account';

            return [
                'type' => 'tracked_account',
                'id' => $trackedAccountId,
                'label' => $label,
            ];
        }

        return match ($action) {
            'competitors.suggest' => [
                'type' => 'competitors',
                'label' => 'Competitors',
            ],
            'influencers.find', 'influencer.brief' => [
                'type' => 'influencers',
                'label' => 'Influencers',
            ],
            'brand.autofill' => [
                'type' => 'brand',
                'label' => 'Brand',
            ],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function analyzeDescription(array $meta): string
    {
        $platform = $this->platformLabel($meta['platform'] ?? null);
        $noun = $this->contentNoun($meta['platform'] ?? null, $meta['post_type'] ?? null);

        if ($platform !== null) {
            return "Analyzed {$platform} {$noun}";
        }

        return 'Analyzed post';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function syncDescription(array $meta): string
    {
        $platform = $this->platformLabel($meta['platform'] ?? null);
        $kind = $this->accountKindLabel($meta['account_kind'] ?? $meta['kind'] ?? null);
        $handle = $this->stringMeta($meta['handle'] ?? null);

        $parts = array_values(array_filter([
            $platform,
            $kind !== null ? strtolower($kind) : null,
        ]));

        $base = $parts === []
            ? 'Synced account'
            : 'Synced '.implode(' ', $parts);

        if ($handle !== null) {
            return $base.' @'.ltrim($handle, '@');
        }

        return $base;
    }

    private function contentNoun(mixed $platform, mixed $postType): string
    {
        $platformValue = is_string($platform) ? strtolower($platform) : null;
        $typeValue = is_string($postType) ? strtolower($postType) : null;

        if ($platformValue === Platform::Youtube->value) {
            return 'Short';
        }

        return match ($typeValue) {
            PostType::Reel->value => 'Reel',
            PostType::Video->value => 'video',
            PostType::Carousel->value => 'carousel',
            PostType::Image->value => 'image',
            PostType::Text->value => 'post',
            default => 'post',
        };
    }

    private function platformLabel(mixed $platform): ?string
    {
        if (! is_string($platform) || $platform === '') {
            return null;
        }

        return Platform::tryFrom(strtolower($platform))?->label();
    }

    private function accountKindLabel(mixed $kind): ?string
    {
        if (! is_string($kind) || $kind === '') {
            return null;
        }

        // Discovery jobs store kind as search/propose; ignore those for sync copy.
        return TrackedAccountKind::tryFrom(strtolower($kind))?->label();
    }

    private function humanizeAction(string $action): string
    {
        $parts = preg_split('/[._]+/', $action) ?: [$action];
        $words = array_map(
            static fn (string $part): string => $part === '' ? '' : ucfirst($part),
            $parts,
        );

        return trim(implode(' ', array_filter($words)));
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
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
