---
paths:
  - 'resources/css/app.css'
  - 'resources/js/**/*.{vue,ts,css}'
  - 'resources/views/app.blade.php'
---

# Evidence File + Caution Tape tokens

Appearance is light / dark / system via `useAppearance`, the `appearance` cookie, and the `dark` class on `<html>`. When dark is active, set `color-scheme: dark` on the root (CSS + `documentElement.style.colorScheme` in `updateTheme` / the boot script) so native scrollbars follow night; light uses `color-scheme: light`. Dark also styles scrollbar thumbs fog-on-`#1A1A1D`.

The **logged-in app is pinned to Caution Tape night** whatever the Appearance cookie says. `HandleAppearance` shares `appNight` when a user is authenticated. Blade sets `data-app-night="1"` and `html.dark`. `updateTheme` must not remove `dark` while `data-app-night="1"`. Charts read `snitchIsDark()` (html.dark, data-app-night, or `.snitch-app-night`). Settings hides the Appearance nav and redirects `/settings/appearance` to profile. Leave `settings/Appearance.vue` and `AppearanceTabs` in place so the setting can come back.

## Palettes

- **Logged-in app (Caution Tape night, always):** paper `#0E0E10`, fog type `#EDEAE2`, panels `#141416`, bold yellow `#FCD700`, pink alerts `#FF3D8B`, thin `white/10` borders. Sidebar is black (`#000000`) with the active item a yellow `#FCD700` block and black text. Fonts: Inter (body), Archivo Black (`font-hero` / `.snitch-hero-display` page and section headlines, uppercase), Space Mono (small labels and stats only). Primary buttons are solid yellow with black uppercase text. Secondary buttons are outlined cream. Format tags use the landing small-label style (mono, yellow type, no slab). Highlight **one** key word or number per headline with `.snitch-highlight` (`#FCD700` block, `#0E0E10` text). Big tile numbers are cream; yellow text (no block) only for the single most important stat. Optional one `.snitch-caution-band` per page (yellow ground, black cards, numbered yellow tiles).
- **:root Evidence File tokens** stay defined for public `/beta` (`snitch-light`) and a future Appearance return: cream paper `#F3EEE3`, near-black ink `#141414`, highlighter yellow `#FFD60A`, alert red `#E5341D`.
- **Public shell (Caution Tape):** same night tokens, pinned via `PublicLayout` `snitch-public-shell dark` so appearance does not flip marketing pages. Hero headlines use Archivo Black (`font-hero` / `.snitch-hero-display`) in caps. TrackedBy keeps its own Caution Tape treatment in both app modes.

## Token rules

- Flip `--snitch-paper`, `--snitch-ink`, `--snitch-fog`, `--snitch-spot`, and related grade / halftone / washi under `.dark` so `snitch-*` utilities and `text-snitch-ink` / `bg-snitch-paper` / `bg-snitch-lift` work everywhere without per-page `dark:` classes.
- Prefer `bg-snitch-lift` over bare `bg-white` for cards and panels.
- Keep `--snitch-on-spot` near-black for text on yellow fills. Never leave fog/cream type on a yellow face.
- Use `--snitch-lift` instead of bare `white` in `color-mix` surface lifts.
- Use `--snitch-print-blend` (`multiply` light / `soft-light` dark) for print overlays on paper.
- Light spot stays `#FFD60A`; dark / app spot is `#FCD700`. Genuine errors may stay `#E5341D`. App alerts and "lower than" badges use pink `#FF3D8B`.
- Caution Tape tokens (`--snitch-caution-*`) stay fixed for landing accents and TrackedBy.
- Chart series read live CSS vars (`snitchTheme.ts`). Dark: You = `#FCD700`, rivals = pink/blue/green/orange (`SNITCH_RIVAL_COLOURS_DARK`), rivals' average = dashed fog. Light: You = ink, existing teal/red rival set. Growth filter chips, legends, and lines share `snitchAccountColour(handle)` (stable hash; strip leading `@`) so dots match series in both modes. ApexCharts use `snitchApexTheme()` (`theme.mode: 'dark'` on the night shell) so axis labels and tooltips stay readable.
- Line charts must join sparse weeks: ApexCharts 7 has no `connectNulls`, so null category slots break strokes. Use `resources/js/lib/lineChart.ts` (`connectedLineData` + datetime `{x,y}` points, drop nulls). You stroke width 3; rivals 2. Empty post weeks stay null in `GrowthMetricsBuilder` (no false zero plunge); Average X× usual stays null until PI has ≥3 priors.
- Never invert ink↔paper for selected chips in dark (pale pill + pale text). Use `.snitch-choice` / `.snitch-choice-active` (light selected = ink + paper text; dark selected = spot yellow + on-spot text; idle = lift / `#1A1A1D` + fog border). Format tags use `.snitch-format-tag` (yellow mono label on night; no yellow slab). Meter bars use `.snitch-meter-fill` / `-lead` (ink in light; dark lead stays yellow). Heat / density cells use `--snitch-heat` (ink in light, yellow in dark).
- Light sidebar active item highlights the label only (`html:not(.dark) ... [data-active=true] > span:last-child`), not a full-width yellow bar. Dark / app-night sidebar keeps the yellow fill.

## Public shell is pinned to Caution Tape night

- `PublicLayout` carries `snitch-public-shell dark`, so every public page (landing, pricing, blog, legal, contact, 404, agents, analytics, onboarding) renders the night snitch tokens and `dark:` variants whatever the OS / appearance cookie. Light and dark visitors see the same page; the logged-in app is also pinned to night via `appNight`.
- Public page copy uses Caution Tape classes (`text-snitch-caution-fog` + `/70`-`/85` for secondary, `snitch-hero-display` headings, `border-white/10 bg-[#141416]` panels, yellow `bg-snitch-caution-yellow` primary / fog-outline secondary buttons). Never `text-neutral-950`, `bg-white` cards, `text-xs`, or `line-clamp` on the black shell.
- Sections that are deliberately cream/white (e.g. `/beta`) add `snitch-light` to re-scope the day tokens.
