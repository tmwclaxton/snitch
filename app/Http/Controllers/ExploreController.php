<?php

namespace App\Http\Controllers;

use App\Enums\AnalysisStatus;
use App\Enums\AnalysisTermDimension;
use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\AnalysisTerm;
use App\Models\Post;
use App\Services\Analysis\AnalysisTermCatalogue;
use App\Support\PlatformEmbed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ExploreController extends Controller
{
    public function __construct(
        private AnalysisTermCatalogue $catalogue,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Post::class);

        $user = $request->user();

        $hookTypes = $this->slugList($request, 'hook_types', 'hook_type');
        $topics = $this->slugList($request, 'topics', 'topic');
        $visualCrafts = $this->slugList($request, 'visual_crafts', 'visual_craft');
        $platform = $this->nullableString($request->query('platform'));
        $queryText = $this->nullableString($request->query('q'));

        $query = Post::query()
            ->where('user_id', $user->id)
            ->reelLike()
            ->whereHas('analysis', function (Builder $analysis): void {
                $analysis->where('status', AnalysisStatus::Completed);
            })
            ->with(['trackedAccount', 'analysis.terms', 'winnerInsight'])
            ->latest('posted_at');

        if ($platform !== null && in_array($platform, array_column(Platform::cases(), 'value'), true)) {
            $query->where('platform', $platform);
        }

        if ($hookTypes !== []) {
            $this->constrainByTermSlugs($query, AnalysisTermDimension::HookType, $hookTypes);
        }

        if ($topics !== []) {
            $this->constrainByTermSlugs($query, AnalysisTermDimension::Topic, $topics);
        }

        if ($visualCrafts !== []) {
            $this->constrainByTermSlugs($query, AnalysisTermDimension::VisualCraft, $visualCrafts);
        }

        if ($queryText !== null) {
            $like = '%'.$queryText.'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder
                    ->where('caption', 'like', $like)
                    ->orWhereHas('analysis', function (Builder $analysis) use ($like): void {
                        $analysis
                            ->where('hook', 'like', $like)
                            ->orWhere('concept', 'like', $like)
                            ->orWhere('idea', 'like', $like)
                            ->orWhere('visual_summary', 'like', $like)
                            ->orWhere('topics', 'like', $like)
                            ->orWhere('custom_tags', 'like', $like);
                    });
            });
        }

        $posts = $query->paginate(24)->withQueryString();
        $posts->getCollection()->transform(function (Post $post): Post {
            $post->setAttribute(
                'embed',
                PlatformEmbed::resolve($post->platform, $post->url, compact: true),
            );

            if ($post->analysis !== null) {
                $post->analysis->setAttribute(
                    'term_labels',
                    $this->catalogue->frontendLabels($post->analysis->terms),
                );
            }

            return $post;
        });

        $sections = $this->catalogue->sectionByKey();
        $termCounts = $this->termUsageCounts($user->id);
        $terms = AnalysisTerm::query()
            ->orderBy('dimension')
            ->orderBy('label')
            ->get(['id', 'dimension', 'slug', 'label'])
            ->map(function (AnalysisTerm $term) use ($sections, $termCounts): array {
                $dimension = $term->dimension instanceof AnalysisTermDimension
                    ? $term->dimension->value
                    : (string) $term->dimension;

                return [
                    'id' => $term->id,
                    'dimension' => $dimension,
                    'slug' => $term->slug,
                    'label' => $term->label,
                    'section' => $sections[$dimension.':'.$term->slug] ?? 'Other',
                    'count' => $termCounts[$term->id] ?? 0,
                ];
            })
            ->groupBy('dimension')
            ->map(fn ($group) => $group->values()->all());

        return Inertia::render('explore/Index', [
            'posts' => $posts,
            'filters' => [
                'q' => $queryText,
                'hook_types' => $hookTypes,
                'topics' => $topics,
                'visual_crafts' => $visualCrafts,
                'platform' => $platform,
            ],
            'terms' => [
                'hook_type' => $terms->get('hook_type', []),
                'topic' => $terms->get('topic', []),
                'visual_craft' => $terms->get('visual_craft', []),
            ],
            'platforms' => collect(Platform::cases())->map(fn (Platform $p) => $p->value)->values(),
        ]);
    }

    /**
     * How often each catalogue term appears on the user's completed reel-like analyses.
     *
     * @return array<int, int>
     */
    private function termUsageCounts(int $userId): array
    {
        return AnalysisTerm::query()
            ->select([
                'analysis_terms.id',
                DB::raw('COUNT(DISTINCT posts.id) as aggregate'),
            ])
            ->join('analysis_term_post_analysis', 'analysis_terms.id', '=', 'analysis_term_post_analysis.analysis_term_id')
            ->join('post_analyses', 'post_analyses.id', '=', 'analysis_term_post_analysis.post_analysis_id')
            ->join('posts', 'posts.id', '=', 'post_analyses.post_id')
            ->where('posts.user_id', $userId)
            ->whereIn('posts.type', PostType::analyzableValues())
            ->where('post_analyses.status', AnalysisStatus::Completed)
            ->groupBy('analysis_terms.id')
            ->pluck('aggregate', 'id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @param  Builder<Post>  $query
     * @param  list<string>  $slugs
     */
    private function constrainByTermSlugs(Builder $query, AnalysisTermDimension $dimension, array $slugs): void
    {
        $query->whereHas('analysis.terms', function (Builder $terms) use ($dimension, $slugs): void {
            $terms
                ->where('dimension', $dimension->value)
                ->whereIn('slug', $slugs);
        });
    }

    /**
     * @return list<string>
     */
    private function slugList(Request $request, string $pluralKey, string $singularKey): array
    {
        $raw = $request->query($pluralKey, $request->query($singularKey));

        if (is_string($raw) || is_numeric($raw)) {
            $raw = [$raw];
        }

        if (! is_array($raw)) {
            return [];
        }

        $slugs = [];
        foreach ($raw as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $trimmed = strtolower(trim((string) $value));
            $trimmed = preg_replace('/[^a-z0-9_\-]/', '', $trimmed) ?? '';

            if ($trimmed !== '') {
                $slugs[] = $trimmed;
            }
        }

        return array_values(array_unique($slugs));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
