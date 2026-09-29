<?php

namespace App\Mcp\Support;

use App\Models\Post;

final class McpAppUrls
{
    public static function feedPost(Post|int $post): string
    {
        $id = $post instanceof Post ? (int) $post->id : $post;

        return rtrim((string) config('app.url'), '/').'/feed/'.$id;
    }

    public static function winners(): string
    {
        return rtrim((string) config('app.url'), '/').'/winners';
    }

    public static function explore(): string
    {
        return rtrim((string) config('app.url'), '/').'/explore';
    }

    public static function brief(?string $week = null): string
    {
        $url = self::origin().'/brief';

        return $week === null || $week === '' ? $url : $url.'?week='.$week;
    }

    public static function growth(string $period = '30d'): string
    {
        return self::origin().'/growth?period='.$period;
    }

    public static function monthlyReport(?string $month = null): string
    {
        $url = self::origin().'/growth/report';

        return $month === null || $month === '' ? $url : $url.'?month='.$month;
    }

    private static function origin(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * @param  iterable<int, Post>  $posts
     */
    public static function attachSnitchUrls(iterable $posts): void
    {
        foreach ($posts as $post) {
            if (! $post instanceof Post) {
                continue;
            }

            $post->setAttribute('snitch_url', self::feedPost($post));
        }
    }
}
