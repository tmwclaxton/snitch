<?php

namespace Database\Factories;

use App\Enums\BlogStatus;
use App\Models\Blog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Blog>
 */
class BlogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence(6);

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(5),
            'excerpt' => fake()->paragraph(2),
            'body' => "## First section\n\n".fake()->paragraphs(3, true),
            'image_url' => null,
            'tags' => fake()->randomElements(
                ['competitor-tracking', 'tiktok', 'instagram', 'hooks', 'winners', 'agencies'],
                3,
            ),
            'sources' => [
                [
                    'title' => 'Snitch',
                    'url' => 'https://www.snitchsocial.net',
                    'description' => 'Competitor social tracking for brands and agencies.',
                ],
            ],
            'status' => BlogStatus::Draft,
            'published_at' => null,
            'view_count' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => BlogStatus::Published,
            'published_at' => now()->subHour(),
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'status' => BlogStatus::Draft,
            'published_at' => null,
        ]);
    }
}
