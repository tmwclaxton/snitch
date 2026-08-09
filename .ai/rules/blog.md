---
paths:
  - 'app/Models/Blog.php'
  - 'app/Enums/BlogStatus.php'
  - 'app/Http/Controllers/BlogController.php'
  - 'app/Services/Blog/**'
  - 'app/Console/Commands/GenerateBlogPostCommand.php'
  - 'app/Console/Commands/PublishBlogDraftsCommand.php'
  - 'config/blog.php'
  - 'resources/js/pages/blog/**'
  - 'routes/web.php'
  - 'routes/console.php'
---

# Blog (AI content marketing)

Public blog posts live in the `blogs` table (Markdown body). There is no in-app CMS.

## Rules

- Routes: `/blog` (`blog.index`), `/blog/{slug}` (`blog.show`). Only `published` + past `published_at` are public.
- Render Markdown server-side with `SafeMarkdown::toHtml` into `body_html` for Inertia.
- SEO for the index is in `config/seo.php` (`blog.index`). Post pages resolve dynamically in `App\Support\Seo::forRequest` (Article JSON-LD, hero OG image).
- Sitemap appends every published post via `Seo::sitemapEntries()`.
- Content ops are CLI: `blog:generate` (default status from `config/blog.php`, usually `draft`) then `blog:publish` after spot-check. Weekly schedule runs `blog:generate --length=long`.
- Generated product links must use `config('blog.public_site_url')` (`https://www.snitchsocial.net`), never localhost.
- Hero images store on the `public` disk under `blogs/heroes/`.
- UI uses `PublicLayout` + soft risograph scrap/doc patterns; keep `blog/` out of the authenticated `AppLayout` switch in `app.ts`.
