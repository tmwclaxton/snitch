<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisTermDimension;
use App\Models\AnalysisTerm;
use Illuminate\Support\Collection;

class AnalysisTermCatalogue
{
    /**
     * @return list<array{dimension: string, slug: string, label: string, section: string}>
     */
    public function definitions(): array
    {
        /** @var list<array{dimension: string, slug: string, label: string, section: string}> $rows */
        $rows = require database_path('data/analysis_terms.php');

        return $rows;
    }

    /**
     * @return array<string, string> keyed by "dimension:slug"
     */
    public function sectionByKey(): array
    {
        $map = [];

        foreach ($this->definitions() as $row) {
            $map[$row['dimension'].':'.$row['slug']] = $row['section'];
        }

        return $map;
    }

    public function syncToDatabase(): int
    {
        $count = 0;

        foreach ($this->definitions() as $row) {
            AnalysisTerm::query()->updateOrCreate(
                [
                    'dimension' => $row['dimension'],
                    'slug' => $row['slug'],
                ],
                [
                    'label' => $row['label'],
                ],
            );
            $count++;
        }

        return $count;
    }

    /**
     * @return Collection<string, list<string>>
     */
    public function slugsByDimension(): Collection
    {
        return collect($this->definitions())
            ->groupBy('dimension')
            ->map(fn (Collection $rows): array => $rows->pluck('slug')->values()->all());
    }

    /**
     * Compact prompt block listing catalogue slugs by dimension.
     */
    public function promptBlock(): string
    {
        $lines = ['Controlled catalogue (prefer these slugs):'];

        foreach (AnalysisTermDimension::cases() as $dimension) {
            $slugs = $this->slugsByDimension()->get($dimension->value, []);
            $lines[] = $dimension->value.': '.implode(', ', $slugs);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $slugs
     * @return list<int>
     */
    public function resolveIds(AnalysisTermDimension $dimension, array $slugs): array
    {
        $normalized = collect($slugs)
            ->map(fn (mixed $slug): string => strtolower(trim((string) $slug)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($normalized === []) {
            return [];
        }

        return AnalysisTerm::query()
            ->where('dimension', $dimension)
            ->whereIn('slug', $normalized)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<string>  $slugs
     * @return list<string>
     */
    public function resolveLabels(AnalysisTermDimension $dimension, array $slugs): array
    {
        $normalized = collect($slugs)
            ->map(fn (mixed $slug): string => strtolower(trim((string) $slug)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($normalized === []) {
            return [];
        }

        return AnalysisTerm::query()
            ->where('dimension', $dimension)
            ->whereIn('slug', $normalized)
            ->orderBy('label')
            ->pluck('label')
            ->map(fn ($label): string => (string) $label)
            ->all();
    }
}
