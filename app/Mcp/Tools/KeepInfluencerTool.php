<?php

namespace App\Mcp\Tools;

use App\Enums\TrackedAccountKind;
use App\Jobs\FindInfluencersJob;
use App\Jobs\SyncTrackedAccountJob;
use App\Mcp\Support\McpAuth;
use App\Models\TrackedAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('keep_influencer')]
#[Description('Keep a discovered influencer as a tracked account and queue sync (billable). Prefer passing run_id so fit_reason/url are copied from the find payload. Response includes fit_reason and profile url.')]
class KeepInfluencerTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'platform' => ['required', 'string', 'in:instagram,tiktok,youtube,facebook,linkedin'],
            'handle' => ['required', 'string', 'max:255'],
            'run_id' => ['nullable', 'string'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:2048'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'fit_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $handle = ltrim($data['handle'], '@');
        $suggestion = null;

        if (! empty($data['run_id'])) {
            if (! Str::isUuid($data['run_id'])) {
                return Response::error('Invalid run_id.');
            }

            $payload = Cache::get(FindInfluencersJob::cacheKeyFor($user->id, $data['run_id']));

            if (! is_array($payload)) {
                return Response::error('Run not found.');
            }

            foreach ($payload['suggestions'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $rowPlatform = (string) ($row['platform'] ?? '');
                $rowHandle = ltrim((string) ($row['handle'] ?? ''), '@');

                if ($rowPlatform === $data['platform'] && strcasecmp($rowHandle, $handle) === 0) {
                    $suggestion = $row;
                    break;
                }
            }

            $decisions = is_array($payload['decisions'] ?? null) ? $payload['decisions'] : [];
            $decisions["{$data['platform']}:{$handle}"] = 'kept';
            $payload['decisions'] = $decisions;
            Cache::put(
                FindInfluencersJob::cacheKeyFor($user->id, $data['run_id']),
                $payload,
                now()->addHours(2),
            );
        }

        $fitReason = trim((string) ($data['fit_reason']
            ?? ($suggestion['fit_reason'] ?? '')));
        $url = $data['url']
            ?? (isset($suggestion['url']) ? (string) $suggestion['url'] : null);
        $displayName = $data['display_name']
            ?? (isset($suggestion['display_name']) ? (string) $suggestion['display_name'] : null);
        $avatar = isset($suggestion['avatar']) && is_string($suggestion['avatar'])
            ? $suggestion['avatar']
            : null;

        $account = TrackedAccount::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $data['platform'],
                'handle' => $handle,
            ],
            [
                'kind' => TrackedAccountKind::Influencer,
                'external_id' => $data['external_id'] ?? ($suggestion['external_id'] ?? null),
                'url' => $url,
                'display_name' => $displayName,
                'avatar' => $avatar,
                'fit_reason' => $fitReason !== '' ? Str::limit($fitReason, 280, '') : null,
            ],
        );

        SyncTrackedAccountJob::dispatch($account->id, true);

        return Response::json([
            'tracked_account' => $account->only([
                'id',
                'platform',
                'handle',
                'kind',
                'url',
                'display_name',
                'fit_reason',
            ]),
            'note' => 'Influencer kept and sync queued. fit_reason explains brand-deal fit when present.',
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'platform' => $schema->string()->required(),
            'handle' => $schema->string()->required(),
            'run_id' => $schema->string()->nullable(),
            'external_id' => $schema->string()->nullable(),
            'url' => $schema->string()->nullable(),
            'display_name' => $schema->string()->nullable(),
            'fit_reason' => $schema->string()->nullable(),
        ];
    }
}
