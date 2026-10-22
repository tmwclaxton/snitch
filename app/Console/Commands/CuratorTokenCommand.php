<?php

namespace App\Console\Commands;

use App\Mcp\Support\McpAuth;
use App\Models\User;
use Illuminate\Console\Command;

class CuratorTokenCommand extends Command
{
    protected $signature = 'snitch:curator-token
        {action : create|revoke|list}
        {--email= : Admin user email to attach the curator token to}
        {--user= : Admin user id to attach the curator token to}
        {--token-id= : Sanctum token id to revoke}
        {--all : With revoke, delete every curator token}
        {--write= : Optional path to write the plain token (chmod 600)}';

    protected $description = 'Create, list, or revoke curator-scoped Sanctum MCP tokens';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'create' => $this->createToken(),
            'revoke' => $this->revokeToken(),
            'list' => $this->listTokens(),
            default => $this->invalidAction(),
        };
    }

    private function createToken(): int
    {
        $user = $this->resolveUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $user->sanctumTokens()->where('name', McpAuth::CURATOR_TOKEN_NAME)->delete();
        $plain = $user->createSanctumToken(
            McpAuth::CURATOR_TOKEN_NAME,
            [McpAuth::CURATOR_ABILITY],
        )->plainTextToken;

        $writePath = $this->option('write');
        if (is_string($writePath) && $writePath !== '') {
            $dir = dirname($writePath);
            if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
                $this->error('Could not create directory for token file: '.$dir);

                return self::FAILURE;
            }

            if (file_put_contents($writePath, $plain.PHP_EOL) === false) {
                $this->error('Could not write token file: '.$writePath);

                return self::FAILURE;
            }

            chmod($writePath, 0600);
            $this->info('Curator token written to '.$writePath);
        } else {
            $this->line($plain);
        }

        $this->info('Curator token created for user #'.$user->id.' ('.$user->email.').');

        return self::SUCCESS;
    }

    private function revokeToken(): int
    {
        if ((bool) $this->option('all')) {
            $deleted = 0;
            foreach (User::query()->cursor() as $user) {
                $deleted += $user->sanctumTokens()
                    ->where('name', McpAuth::CURATOR_TOKEN_NAME)
                    ->delete();
            }
            $this->info('Revoked '.$deleted.' curator token(s) (all).');

            return self::SUCCESS;
        }

        $tokenId = $this->option('token-id');
        if ($tokenId !== null && $tokenId !== '') {
            $deleted = 0;
            foreach (User::query()->cursor() as $user) {
                $deleted += $user->sanctumTokens()
                    ->where('name', McpAuth::CURATOR_TOKEN_NAME)
                    ->where('id', (int) $tokenId)
                    ->delete();
            }
            $this->info('Revoked '.$deleted.' curator token(s) with id '.$tokenId);

            return self::SUCCESS;
        }

        $user = $this->resolveUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $deleted = $user->sanctumTokens()->where('name', McpAuth::CURATOR_TOKEN_NAME)->delete();
        $this->info('Revoked '.$deleted.' curator token(s) for user #'.$user->id);

        return self::SUCCESS;
    }

    private function listTokens(): int
    {
        $rows = [];
        foreach (User::query()->orderBy('id')->cursor() as $user) {
            foreach ($user->sanctumTokens()->where('name', McpAuth::CURATOR_TOKEN_NAME)->get() as $token) {
                $rows[] = [
                    $token->id,
                    $user->id,
                    $user->email,
                    $token->last_used_at?->toDateTimeString() ?? '-',
                    $token->created_at?->toDateTimeString() ?? '-',
                ];
            }
        }

        if ($rows === []) {
            $this->info('No curator tokens.');

            return self::SUCCESS;
        }

        $this->table(['token_id', 'user_id', 'email', 'last_used_at', 'created_at'], $rows);

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $userId = $this->option('user');
        $email = $this->option('email');

        if ($userId !== null && $userId !== '') {
            $user = User::query()->find((int) $userId);
            if ($user === null) {
                $this->error('User not found: '.$userId);

                return null;
            }

            return $user;
        }

        if (is_string($email) && $email !== '') {
            $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
            if ($user === null) {
                $this->error('User not found for email: '.$email);

                return null;
            }

            return $user;
        }

        $user = User::query()->where('is_admin', true)->orderBy('id')->first()
            ?? User::query()->orderBy('id')->first();

        if ($user === null) {
            $this->error('No users exist to attach a curator token to.');

            return null;
        }

        return $user;
    }

    private function invalidAction(): int
    {
        $this->error('Action must be create, revoke, or list.');

        return self::FAILURE;
    }
}
