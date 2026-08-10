<?php

namespace App\Services\SocialAccounts;

use App\Enums\Platform;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\DB;

class SocialAccountResolver
{
    /**
     * Find or create the global social account for a platform handle / external id.
     *
     * @param  array{
     *     url?: string|null,
     *     avatar?: string|null,
     *     display_name?: string|null
     * }  $attributes
     */
    public function resolve(
        Platform|string $platform,
        string $handle,
        ?string $externalId = null,
        array $attributes = [],
    ): SocialAccount {
        $platformValue = $platform instanceof Platform ? $platform->value : strtolower($platform);
        $normalizedHandle = $this->normalizeHandle($handle);
        $externalId = filled($externalId) ? (string) $externalId : null;

        return DB::transaction(function () use ($platformValue, $normalizedHandle, $externalId, $attributes): SocialAccount {
            $social = null;

            if ($externalId !== null) {
                $social = SocialAccount::query()
                    ->where('platform', $platformValue)
                    ->where('external_id', $externalId)
                    ->first();
            }

            $social ??= SocialAccount::query()
                ->where('platform', $platformValue)
                ->where('handle', $normalizedHandle)
                ->first();

            if ($social === null) {
                return SocialAccount::query()->create([
                    'platform' => $platformValue,
                    'handle' => $normalizedHandle,
                    'external_id' => $externalId,
                    'url' => $attributes['url'] ?? null,
                    'avatar' => $attributes['avatar'] ?? null,
                    'display_name' => $attributes['display_name'] ?? null,
                ]);
            }

            $updates = array_filter([
                'handle' => $normalizedHandle,
                'external_id' => $externalId ?? $social->external_id,
                'url' => $attributes['url'] ?? null,
                'avatar' => $attributes['avatar'] ?? null,
                'display_name' => $attributes['display_name'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            if ($updates !== []) {
                $social->fill($updates)->save();
            }

            return $social->refresh();
        });
    }

    public function normalizeHandle(string $handle): string
    {
        return mb_strtolower(ltrim(trim($handle), '@'));
    }
}
