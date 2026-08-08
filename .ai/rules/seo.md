---
paths:
  - 'config/seo.php'
  - 'app/Support/Seo.php'
  - 'resources/views/app.blade.php'
  - 'resources/js/components/marketing/SeoHead.vue'
  - 'resources/js/components/AppHead.vue'
  - 'resources/js/layouts/PublicLayout.vue'
  - 'public/robots.txt'
  - 'routes/web.php'
  - 'bootstrap/app.php'
---

# SEO (first-byte meta)

Public SEO copy and indexability live in `config/seo.php`. `App\Support\Seo::forRequest()` resolves the shared Inertia `seo` prop and the Blade first-byte tags in `app.blade.php`.

## Rules

- Do not invent per-page meta strings in Vue marketing pages; `PublicLayout` renders `SeoHead`, which reads `page.props.seo`.
- Keep Blade meta and `SeoHead` aligned via the same resolver (`head-key` attributes so SPA navigations replace tags).
- Absolute canonical / OG image URLs must use `config('app.url')` (www in production), not `window.location.origin`.
- Only routes listed under `seo.pages` are `index, follow`. Auth app surfaces, onboarding, and 404 are `noindex, nofollow`.
- Unmatched 404s skip the `web` middleware group; `bootstrap/app.php` must share `seo` (and shell props) before rendering `errors/NotFound`.
- Sitemap entries come from `Seo::sitemapEntries()`; never list disallowed app paths.
- `public/robots.txt` Sitemap line must stay absolute: `https://www.snitchsocial.net/sitemap.xml`.
- In `SeoHead.vue`, never put a raw `<script>` inside the Vue template (Vue ignores side-effect tags and Vite fails). Render JSON-LD with `<component :is="'script'" type="application/ld+json" v-text="...">`.
