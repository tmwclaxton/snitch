<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Support\SafeMarkdown;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class BlogController extends Controller
{
    public function index(): Response
    {
        $posts = Blog::query()
            ->published()
            ->select(['id', 'title', 'slug', 'excerpt', 'image_url', 'tags', 'published_at', 'view_count'])
            ->orderByDesc('published_at')
            ->paginate(12);

        return Inertia::render('blog/Index', [
            'posts' => $posts,
        ]);
    }

    public function show(Blog $blog): Response
    {
        abort_unless(
            $blog->status->value === 'published'
            && $blog->published_at !== null
            && $blog->published_at->isPast(),
            404,
        );

        $blog->increment('view_count');

        $postUrl = route('blog.show', $blog, absolute: true);
        $bodyHtml = SafeMarkdown::toHtml((string) $blog->body) ?? '';

        return Inertia::render('blog/Show', [
            'post' => [
                'id' => $blog->id,
                'title' => $blog->title,
                'slug' => $blog->slug,
                'excerpt' => $blog->excerpt,
                'body_html' => $bodyHtml,
                'image_url' => $blog->image_url,
                'tags' => $blog->tags ?? [],
                'sources' => $blog->sources ?? [],
                'published_at' => $blog->published_at?->toIso8601String(),
                'view_count' => $blog->view_count,
                'url' => $postUrl,
            ],
            'more_posts' => $this->morePostsForArticle($blog),
            'share_links' => $this->shareLinks($postUrl, $blog->title),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function shareLinks(string $url, string $title): array
    {
        $encodedUrl = rawurlencode($url);
        $encodedTitle = rawurlencode($title);
        $encodedText = rawurlencode($title.' '.$url);

        return [
            'facebook' => "https://www.facebook.com/sharer/sharer.php?u={$encodedUrl}",
            'twitter' => "https://twitter.com/intent/tweet?url={$encodedUrl}&text={$encodedTitle}",
            'linkedin' => "https://www.linkedin.com/sharing/share-offsite/?url={$encodedUrl}",
            'whatsapp' => "https://wa.me/?text={$encodedText}",
        ];
    }

    /**
     * @return Collection<int, array{id: int, title: string, slug: string, excerpt: string, image_url: ?string, tags: array<int, string>, published_at: ?string}>
     */
    protected function morePostsForArticle(Blog $blog): Collection
    {
        $columns = ['id', 'title', 'slug', 'excerpt', 'image_url', 'tags', 'published_at'];

        $candidates = Blog::query()
            ->published()
            ->where('id', '!=', $blog->id)
            ->orderByDesc('published_at')
            ->limit(50)
            ->get($columns);

        if ($candidates->isEmpty()) {
            return collect();
        }

        $take = min(3, $candidates->count());
        $currentTags = array_values(array_unique(array_filter($blog->tags ?? [])));

        $picked = $currentTags === []
            ? $candidates->random($take)->values()
            : $this->pickByTagOverlap($candidates, $currentTags, $take);

        return $picked->map(fn (Blog $post): array => [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt,
            'image_url' => $post->image_url,
            'tags' => $post->tags ?? [],
            'published_at' => $post->published_at?->toIso8601String(),
        ]);
    }

    /**
     * @param  Collection<int, Blog>  $candidates
     * @param  list<string>  $currentTags
     * @return Collection<int, Blog>
     */
    protected function pickByTagOverlap(Collection $candidates, array $currentTags, int $take): Collection
    {
        $scored = $candidates->map(fn (Blog $post): array => [
            'post' => $post,
            'overlap' => count(array_intersect($currentTags, $post->tags ?? [])),
        ]);

        if ((int) $scored->max('overlap') === 0) {
            return $candidates->random($take)->values();
        }

        return $scored
            ->filter(fn (array $row): bool => $row['overlap'] > 0)
            ->sort(function (array $a, array $b): int {
                if ($a['overlap'] !== $b['overlap']) {
                    return $b['overlap'] <=> $a['overlap'];
                }

                return ($b['post']->published_at?->timestamp ?? 0)
                    <=> ($a['post']->published_at?->timestamp ?? 0);
            })
            ->take($take)
            ->pluck('post')
            ->values();
    }
}
